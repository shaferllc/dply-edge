package main

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"log"
	"net"
	"os"
	"strconv"
	"time"

	corev1 "k8s.io/api/core/v1"
	apierrors "k8s.io/apimachinery/pkg/api/errors"
	"k8s.io/apimachinery/pkg/api/resource"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/types"
	"k8s.io/apimachinery/pkg/util/intstr"
)

// tenant is one app's Valkey, stored as a Secret named vk-{id}.
type tenant struct {
	ID         string `json:"id"`
	Password   string `json:"password"`
	MemoryMB   int    `json:"memory_mb"`
	SleepAfter int    `json:"sleep_after"` // seconds idle before sleeping; 0 stays on
	Persistent bool   `json:"persistent"`  // pro: AOF on a volume, never sleeps
	Engine     string `json:"engine"`      // valkey (default), postgres
	DiskGB     int    `json:"disk_gb"`     // databases: volume size
}

func objectName(id string) string { return "vk-" + id }

func (g *gateway) saveTenant(ctx context.Context, t tenant) error {
	if t.Persistent && !isDatabase(t.Engine) {
		t.SleepAfter = 0 // Valkey pro stays on; databases sleep with their data on the volume
	}
	secret := &corev1.Secret{
		ObjectMeta: metav1.ObjectMeta{
			Name:   objectName(t.ID),
			Labels: map[string]string{"app": "dply-valkey", "tenant": t.ID},
		},
		StringData: map[string]string{
			"id":          t.ID,
			"password":    t.Password,
			"memory_mb":   strconv.Itoa(t.MemoryMB),
			"sleep_after": strconv.Itoa(t.SleepAfter),
			"persistent":  strconv.FormatBool(t.Persistent),
			"engine":      t.Engine,
			"disk_gb":     strconv.Itoa(t.DiskGB),
		},
	}
	secrets := g.kube.CoreV1().Secrets(g.cfg.namespace)
	existing, err := secrets.Get(ctx, secret.Name, metav1.GetOptions{})
	if apierrors.IsNotFound(err) {
		_, err = secrets.Create(ctx, secret, metav1.CreateOptions{})
		return err
	}
	if err != nil {
		return err
	}
	existing.Data = nil
	existing.StringData = secret.StringData
	_, err = secrets.Update(ctx, existing, metav1.UpdateOptions{})
	return err
}

func tenantFromSecret(s *corev1.Secret) tenant {
	return tenant{
		ID:         string(s.Data["id"]),
		Password:   string(s.Data["password"]),
		MemoryMB:   atoi(string(s.Data["memory_mb"])),
		SleepAfter: atoi(string(s.Data["sleep_after"])),
		Persistent: string(s.Data["persistent"]) == "true",
		Engine:     engineOrValkey(string(s.Data["engine"])),
		DiskGB:     atoi(string(s.Data["disk_gb"])),
	}
}

func engineOrValkey(e string) string {
	if e == "" {
		return "valkey"
	}
	return e
}

func debugTiming() bool { return os.Getenv("DEBUG_TIMING") != "" }

func (g *gateway) getTenantRecord(ctx context.Context, id string) (*tenant, error) {
	s, err := g.kube.CoreV1().Secrets(g.cfg.namespace).Get(ctx, objectName(id), metav1.GetOptions{})
	if apierrors.IsNotFound(err) {
		return nil, errNotFound
	}
	if err != nil {
		return nil, err
	}
	t := tenantFromSecret(s)
	return &t, nil
}

func (g *gateway) listTenants(ctx context.Context) ([]tenant, error) {
	list, err := g.kube.CoreV1().Secrets(g.cfg.namespace).List(ctx, metav1.ListOptions{LabelSelector: "app=dply-valkey"})
	if err != nil {
		return nil, err
	}
	out := make([]tenant, 0, len(list.Items))
	for i := range list.Items {
		out = append(out, tenantFromSecret(&list.Items[i]))
	}
	return out, nil
}

func (g *gateway) deleteTenantRecord(ctx context.Context, id string) error {
	err := g.kube.CoreV1().Secrets(g.cfg.namespace).Delete(ctx, objectName(id), metav1.DeleteOptions{})
	if apierrors.IsNotFound(err) {
		return nil
	}
	return err
}

// Pods are found by label, not name: a tenant can run in a pod adopted from
// the warm pool, which keeps its pool name.
//
//	app=dply-valkey-pod  role=pool|tenant  memory=<mb>  tenant=<id>
func tenantSelector(id string) string { return "app=dply-valkey-pod,role=tenant,tenant=" + id }

// tenantPod returns the tenant's pod when it is running and not being deleted.
// ponytail: one API read per wake; the proxy caches the address after that.
func (g *gateway) tenantPod(ctx context.Context, id string) (*corev1.Pod, bool) {
	list, err := g.kube.CoreV1().Pods(g.cfg.namespace).List(ctx, metav1.ListOptions{LabelSelector: tenantSelector(id)})
	if err != nil {
		return nil, false
	}
	for i := range list.Items {
		p := &list.Items[i]
		if p.DeletionTimestamp == nil && p.Status.Phase == corev1.PodRunning && p.Status.PodIP != "" {
			return p, true
		}
	}
	return nil, false
}

func (g *gateway) podIP(ctx context.Context, id string) (string, bool) {
	p, ok := g.tenantPod(ctx, id)
	if !ok {
		return "", false
	}
	return p.Status.PodIP, true
}

func (g *gateway) deletePod(ctx context.Context, id string) error {
	zero := int64(0)
	return g.kube.CoreV1().Pods(g.cfg.namespace).DeleteCollection(ctx,
		metav1.DeleteOptions{GracePeriodSeconds: &zero},
		metav1.ListOptions{LabelSelector: tenantSelector(id)})
}

func (g *gateway) deletePVC(ctx context.Context, id string) error {
	err := g.kube.CoreV1().PersistentVolumeClaims(g.cfg.namespace).Delete(ctx, objectName(id), metav1.DeleteOptions{})
	if apierrors.IsNotFound(err) {
		return nil
	}
	return err
}

// wake returns an address the tenant can log in to, in this order:
// the cached address, an existing pod, a pod adopted from the warm pool,
// or a new pod. Data from the last sleep is restored before the client
// is let through.
func (g *gateway) wake(ctx context.Context, id string) (string, error) {
	s := g.state(id)
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.ip != "" {
		return s.ip, nil
	}

	t, err := g.getTenantRecord(ctx, id)
	if err != nil {
		return "", err
	}
	if t.Engine != "valkey" {
		return "", fmt.Errorf("tenant %s is a %s database, not Valkey", id, t.Engine)
	}
	admin := g.cfg.adminPassword

	if ip, ok := g.podIP(ctx, id); ok {
		if err := waitReady(ip, admin, 20*time.Second); err != nil {
			return "", err
		}
		if err := g.configure(ip, *t); err != nil {
			return "", err
		}
		s.ip = ip
		g.annotate(ctx, id, func(a map[string]string) {
			if a[annotationSince] == "" {
				a[annotationSince] = strconv.FormatInt(time.Now().Unix(), 10)
			}
		})
		return ip, nil
	}

	step := time.Now()
	lap := func(name string) {
		if debugTiming() {
			log.Printf("tenant %s: %s %s", id, name, time.Since(step).Round(time.Millisecond))
		}
		step = time.Now()
	}
	lap("record")
	ip, pooled := "", false
	if !t.Persistent {
		ip, pooled = g.adopt(ctx, *t)
		lap("adopt")
	}
	if !pooled {
		if ip, err = g.start(ctx, *t); err != nil {
			return "", err
		}
	}
	if err := g.configure(ip, *t); err != nil {
		return "", err
	}
	lap("configure")
	if !t.Persistent {
		if err := g.restore(ctx, *t, ip); err != nil {
			return "", err
		}
		lap("restore")
	}
	s.lastActivity = time.Now()
	s.lastSnapshot = time.Now()
	s.ip = ip
	lap("done")
	// Billing only; the client does not wait for the Secret write.
	go g.markAwake(context.Background(), id)
	if pooled {
		log.Printf("tenant %s: adopted a warm pod", id)
	}
	return ip, nil
}

func (g *gateway) configure(ip string, t tenant) error {
	c, err := dialAdmin(net.JoinHostPort(ip, "6379"), g.cfg.adminPassword)
	if err != nil {
		return err
	}
	defer c.Close()
	return applyTenant(c, t)
}

func (g *gateway) restore(ctx context.Context, t tenant, ip string) error {
	body, err := g.store.get(ctx, t.ID)
	if err != nil || body == nil {
		return err
	}
	defer body.Close()
	c, err := dialAdmin(net.JoinHostPort(ip, "6379"), g.cfg.adminPassword)
	if err != nil {
		return err
	}
	defer c.Close()
	n, err := restoreKeys(c, body)
	if err == nil && n > 0 {
		log.Printf("tenant %s: restored %d key(s)", t.ID, n)
	}
	return err
}

// adopt takes a ready pool pod of the tenant's size and makes it the tenant's.
// The label change is a compare-and-swap on resourceVersion, so two gateways
// can never adopt the same pod.
func (g *gateway) adopt(ctx context.Context, t tenant) (string, bool) {
	pods := g.kube.CoreV1().Pods(g.cfg.namespace)
	list, err := pods.List(ctx, metav1.ListOptions{LabelSelector: "app=dply-valkey-pod,role=pool,memory=" + strconv.Itoa(t.MemoryMB)})
	if err != nil {
		return "", false
	}
	for i := range list.Items {
		p := list.Items[i]
		if p.DeletionTimestamp != nil || p.Status.Phase != corev1.PodRunning || p.Status.PodIP == "" || !podReady(&p) {
			continue
		}
		// The resourceVersion makes this a compare-and-swap: a pod another
		// gateway already took fails with a conflict and the next one is tried.
		patch := fmt.Sprintf(`{"metadata":{"resourceVersion":%q,"labels":{"role":"tenant","tenant":%q}}}`, p.ResourceVersion, t.ID)
		if _, err := pods.Patch(ctx, p.Name, types.MergePatchType, []byte(patch), metav1.PatchOptions{}); err != nil {
			if !apierrors.IsConflict(err) {
				log.Printf("tenant %s: adopt %s: %v", t.ID, p.Name, err)
			}
			continue
		}
		return p.Status.PodIP, true
	}
	return "", false
}

func podReady(p *corev1.Pod) bool {
	for _, c := range p.Status.Conditions {
		if c.Type == corev1.PodReady {
			return c.Status == corev1.ConditionTrue
		}
	}
	return false
}

// start creates a pod for the tenant when no warm one fits (a pro tenant, a
// size with no pool, or an empty pool).
func (g *gateway) start(ctx context.Context, t tenant) (string, error) {
	if t.Persistent {
		if err := g.ensurePVC(ctx, t); err != nil {
			return "", err
		}
	}
	pod := g.podSpec(objectName(t.ID), t.MemoryMB, t.Persistent)
	pod.Labels["role"] = "tenant"
	pod.Labels["tenant"] = t.ID
	pods := g.kube.CoreV1().Pods(g.cfg.namespace)
	for i := 0; i < 50; i++ { // a pod left over from a sleep may still be terminating
		if _, err := pods.Get(ctx, pod.Name, metav1.GetOptions{}); apierrors.IsNotFound(err) {
			break
		}
		time.Sleep(100 * time.Millisecond)
	}
	if _, err := pods.Create(ctx, pod, metav1.CreateOptions{}); err != nil && !apierrors.IsAlreadyExists(err) {
		return "", err
	}
	deadline := time.Now().Add(60 * time.Second)
	for time.Now().Before(deadline) {
		if ip, ok := g.podIP(ctx, t.ID); ok {
			return ip, waitReady(ip, g.cfg.adminPassword, time.Until(deadline))
		}
		time.Sleep(50 * time.Millisecond)
	}
	return "", fmt.Errorf("pod did not start in time")
}

// fillPool keeps cfg.pool[mb] ready pods per size. Called from the reaper.
func (g *gateway) fillPool(ctx context.Context) {
	pods := g.kube.CoreV1().Pods(g.cfg.namespace)
	for mb, want := range g.cfg.pool {
		list, err := pods.List(ctx, metav1.ListOptions{LabelSelector: "app=dply-valkey-pod,role=pool,memory=" + strconv.Itoa(mb)})
		if err != nil {
			continue
		}
		have := 0
		for _, p := range list.Items {
			if p.DeletionTimestamp == nil {
				have++
			}
		}
		for ; have < want; have++ {
			name := fmt.Sprintf("vkp-%d-%s", mb, randomSuffix())
			pod := g.podSpec(name, mb, false)
			pod.Labels["role"] = "pool"
			if _, err := pods.Create(ctx, pod, metav1.CreateOptions{}); err != nil {
				log.Printf("pool %dMB: %v", mb, err)
				break
			}
		}
	}
}

func (g *gateway) ensurePVC(ctx context.Context, t tenant) error {
	pvc := &corev1.PersistentVolumeClaim{
		ObjectMeta: metav1.ObjectMeta{Name: objectName(t.ID), Labels: map[string]string{"app": "dply-valkey", "tenant": t.ID}},
		Spec: corev1.PersistentVolumeClaimSpec{
			AccessModes: []corev1.PersistentVolumeAccessMode{corev1.ReadWriteOnce},
			Resources: corev1.VolumeResourceRequirements{Requests: corev1.ResourceList{
				corev1.ResourceStorage: resource.MustParse(strconv.Itoa(t.MemoryMB*2) + "Mi"),
			}},
		},
	}
	_, err := g.kube.CoreV1().PersistentVolumeClaims(g.cfg.namespace).Create(ctx, pvc, metav1.CreateOptions{})
	if apierrors.IsAlreadyExists(err) {
		return nil
	}
	return err
}

// podSpec is the same for pool and tenant pods. Only the gateway's admin user
// can log in until applyTenant turns "default" on.
func (g *gateway) podSpec(name string, memoryMB int, persistent bool) *corev1.Pod {
	mb := strconv.Itoa(memoryMB)
	args := []string{
		"valkey-server",
		"--maxmemory", mb + "mb",
		"--maxmemory-policy", "noeviction",
		"--dir", "/data",
		"--user", "default", "off",
		"--user", adminUser, "on", ">$(ADMIN_PASSWORD)", "~*", "&*", "+@all",
	}
	if persistent {
		args = append(args, "--appendonly", "yes", "--save", "3600 1 300 100")
	} else {
		args = append(args, "--save", "")
	}

	data := corev1.Volume{Name: "data", VolumeSource: corev1.VolumeSource{EmptyDir: &corev1.EmptyDirVolumeSource{}}}
	if persistent {
		data.VolumeSource = corev1.VolumeSource{PersistentVolumeClaim: &corev1.PersistentVolumeClaimVolumeSource{ClaimName: name}}
	}

	// Headroom over maxmemory for Valkey's own bookkeeping.
	limit := resource.MustParse(strconv.Itoa(memoryMB*3/2+32) + "Mi")
	grace := int64(2)
	nodeSelector, tolerations := proPlacement(memoryMB, persistent)
	return &corev1.Pod{
		ObjectMeta: metav1.ObjectMeta{Name: name, Labels: map[string]string{"app": "dply-valkey-pod", "memory": mb}},
		Spec: corev1.PodSpec{
			NodeSelector:                  nodeSelector,
			Tolerations:                   tolerations,
			RestartPolicy:                 corev1.RestartPolicyAlways,
			TerminationGracePeriodSeconds: &grace,
			AutomountServiceAccountToken:  new(bool),
			Volumes:                       []corev1.Volume{data},
			Containers: []corev1.Container{{
				Name:  "valkey",
				Image: g.cfg.image,
				Args:  args,
				Env: []corev1.EnvVar{{Name: "ADMIN_PASSWORD", ValueFrom: &corev1.EnvVarSource{SecretKeyRef: &corev1.SecretKeySelector{
					LocalObjectReference: corev1.LocalObjectReference{Name: g.cfg.adminSecret}, Key: "password",
				}}}},
				Ports:        []corev1.ContainerPort{{ContainerPort: 6379}},
				VolumeMounts: []corev1.VolumeMount{{Name: "data", MountPath: "/data"}},
				ReadinessProbe: &corev1.Probe{
					ProbeHandler:  corev1.ProbeHandler{TCPSocket: &corev1.TCPSocketAction{Port: intstr.FromInt32(6379)}},
					PeriodSeconds: 2,
				},
				Resources: corev1.ResourceRequirements{
					Requests: corev1.ResourceList{corev1.ResourceMemory: resource.MustParse(mb + "Mi"), corev1.ResourceCPU: resource.MustParse("25m")},
					Limits:   corev1.ResourceList{corev1.ResourceMemory: limit},
				},
			}},
		},
	}
}

func randomSuffix() string {
	b := make([]byte, 4)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

// Awake time is kept on the tenant Secret so billing survives a gateway restart:
// dply.dev/awake-since is set while a pod runs; dply.dev/awake-seconds is the
// total of finished stretches. A pod that dies without a sleep loses its
// stretch (under-billing, never over).
const (
	annotationSince   = "dply.dev/awake-since"
	annotationSeconds = "dply.dev/awake-seconds"
)

func (g *gateway) markAwake(ctx context.Context, id string) {
	g.annotate(ctx, id, func(a map[string]string) {
		a[annotationSince] = strconv.FormatInt(time.Now().Unix(), 10)
	})
}

func (g *gateway) markAsleep(ctx context.Context, id string) {
	g.annotate(ctx, id, func(a map[string]string) {
		if since, err := strconv.ParseInt(a[annotationSince], 10, 64); err == nil && since > 0 {
			total, _ := strconv.ParseInt(a[annotationSeconds], 10, 64)
			a[annotationSeconds] = strconv.FormatInt(total+time.Now().Unix()-since, 10)
		}
		delete(a, annotationSince)
	})
}

func (g *gateway) annotate(ctx context.Context, id string, change func(map[string]string)) {
	secrets := g.kube.CoreV1().Secrets(g.cfg.namespace)
	for attempt := 0; attempt < 3; attempt++ {
		s, err := secrets.Get(ctx, objectName(id), metav1.GetOptions{})
		if err != nil {
			return
		}
		if s.Annotations == nil {
			s.Annotations = map[string]string{}
		}
		change(s.Annotations)
		if _, err = secrets.Update(ctx, s, metav1.UpdateOptions{}); !apierrors.IsConflict(err) {
			return
		}
	}
}

// awakeSeconds is the running total per tenant, counting a stretch still in progress.
func (g *gateway) awakeSeconds(ctx context.Context) (map[string]int64, error) {
	list, err := g.kube.CoreV1().Secrets(g.cfg.namespace).List(ctx, metav1.ListOptions{LabelSelector: "app=dply-valkey"})
	if err != nil {
		return nil, err
	}
	now := time.Now().Unix()
	out := map[string]int64{}
	for _, s := range list.Items {
		total, _ := strconv.ParseInt(s.Annotations[annotationSeconds], 10, 64)
		if since, err := strconv.ParseInt(s.Annotations[annotationSince], 10, 64); err == nil && since > 0 {
			total += now - since
		}
		out[string(s.Data["id"])] = total
	}
	return out, nil
}
