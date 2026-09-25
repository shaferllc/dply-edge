package main

import (
	"context"
	"fmt"
	"net"
	"strconv"
	"time"

	corev1 "k8s.io/api/core/v1"
	apierrors "k8s.io/apimachinery/pkg/api/errors"
	"k8s.io/apimachinery/pkg/api/resource"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
)

// tenant is one app's Valkey, stored as a Secret named vk-{id}.
type tenant struct {
	ID         string `json:"id"`
	Password   string `json:"password"`
	MemoryMB   int    `json:"memory_mb"`
	SleepAfter int    `json:"sleep_after"` // seconds idle before sleeping; 0 stays on
	Persistent bool   `json:"persistent"`  // pro: AOF on a volume, never sleeps
}

func objectName(id string) string { return "vk-" + id }

func (g *gateway) saveTenant(ctx context.Context, t tenant) error {
	if t.Persistent {
		t.SleepAfter = 0
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
	}
}

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

// podIP returns the pod's address when it is running and not being deleted.
// ponytail: one API read per new connection; cache it if connection rates get high.
func (g *gateway) podIP(ctx context.Context, id string) (string, bool) {
	pod, err := g.kube.CoreV1().Pods(g.cfg.namespace).Get(ctx, objectName(id), metav1.GetOptions{})
	if err != nil || pod.DeletionTimestamp != nil || pod.Status.Phase != corev1.PodRunning || pod.Status.PodIP == "" {
		return "", false
	}
	return pod.Status.PodIP, true
}

func (g *gateway) deletePod(ctx context.Context, id string) error {
	zero := int64(0)
	err := g.kube.CoreV1().Pods(g.cfg.namespace).Delete(ctx, objectName(id), metav1.DeleteOptions{GracePeriodSeconds: &zero})
	if apierrors.IsNotFound(err) {
		return nil
	}
	return err
}

func (g *gateway) deletePVC(ctx context.Context, id string) error {
	err := g.kube.CoreV1().PersistentVolumeClaims(g.cfg.namespace).Delete(ctx, objectName(id), metav1.DeleteOptions{})
	if apierrors.IsNotFound(err) {
		return nil
	}
	return err
}

// wake returns an address that answers PING, starting the pod first if needed.
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
	if ip, ok := g.podIP(ctx, id); ok {
		if err := waitForPong(ctx, ip, t.Password, 20*time.Second); err != nil {
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

	pods := g.kube.CoreV1().Pods(g.cfg.namespace)
	// A pod left over from a sleep may still be terminating.
	for i := 0; i < 50; i++ {
		if _, err := pods.Get(ctx, objectName(id), metav1.GetOptions{}); apierrors.IsNotFound(err) {
			break
		}
		time.Sleep(100 * time.Millisecond)
	}
	if t.Persistent {
		if err := g.ensurePVC(ctx, *t); err != nil {
			return "", err
		}
	}
	restore := ""
	if !t.Persistent && g.store.exists(ctx, id) {
		if restore, err = g.store.presignGet(ctx, id); err != nil {
			return "", err
		}
	}
	if _, err := pods.Create(ctx, g.podSpec(*t, restore), metav1.CreateOptions{}); err != nil && !apierrors.IsAlreadyExists(err) {
		return "", err
	}

	deadline := time.Now().Add(60 * time.Second)
	for time.Now().Before(deadline) {
		if ip, ok := g.podIP(ctx, id); ok {
			if err := waitForPong(ctx, ip, t.Password, time.Until(deadline)); err != nil {
				return "", err
			}
			s.lastActivity = time.Now()
			s.lastSnapshot = time.Now()
			s.ip = ip
			g.markAwake(ctx, id)
			return ip, nil
		}
		time.Sleep(50 * time.Millisecond)
	}
	return "", fmt.Errorf("pod did not start in time")
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

func (g *gateway) podSpec(t tenant, restoreURL string) *corev1.Pod {
	mb := strconv.Itoa(t.MemoryMB)
	args := []string{
		"valkey-server",
		"--requirepass", "$(VALKEY_PASSWORD)",
		"--maxmemory", mb + "mb",
		"--maxmemory-policy", "noeviction",
		"--dir", "/data",
		// The gateway pulls snapshots over SYNC; a length-prefixed RDB is simpler to read.
		"--repl-diskless-sync", "no",
		"--rename-command", "CONFIG", "",
		"--rename-command", "DEBUG", "",
		"--rename-command", "MODULE", "",
	}
	if t.Persistent {
		args = append(args, "--appendonly", "yes", "--save", "3600 1 300 100")
	} else {
		args = append(args, "--save", "")
	}

	data := corev1.Volume{Name: "data", VolumeSource: corev1.VolumeSource{EmptyDir: &corev1.EmptyDirVolumeSource{}}}
	if t.Persistent {
		data.VolumeSource = corev1.VolumeSource{PersistentVolumeClaim: &corev1.PersistentVolumeClaimVolumeSource{ClaimName: objectName(t.ID)}}
	}

	var inits []corev1.Container
	if restoreURL != "" {
		inits = append(inits, corev1.Container{
			Name:         "restore",
			Image:        "busybox:1.36",
			Command:      []string{"sh", "-c", `wget -q -O /data/dump.rdb "$RESTORE_URL"`},
			Env:          []corev1.EnvVar{{Name: "RESTORE_URL", Value: restoreURL}},
			VolumeMounts: []corev1.VolumeMount{{Name: "data", MountPath: "/data"}},
		})
	}

	// Headroom over maxmemory for Valkey's own bookkeeping and the SYNC fork.
	limit := resource.MustParse(strconv.Itoa(t.MemoryMB*3/2+32) + "Mi")
	grace := int64(2)
	return &corev1.Pod{
		ObjectMeta: metav1.ObjectMeta{Name: objectName(t.ID), Labels: map[string]string{"app": "dply-valkey", "tenant": t.ID}},
		Spec: corev1.PodSpec{
			RestartPolicy:                 corev1.RestartPolicyAlways,
			TerminationGracePeriodSeconds: &grace,
			AutomountServiceAccountToken:  new(bool),
			InitContainers:                inits,
			Volumes:                       []corev1.Volume{data},
			Containers: []corev1.Container{{
				Name:  "valkey",
				Image: g.cfg.image,
				Args:  args,
				Env: []corev1.EnvVar{{Name: "VALKEY_PASSWORD", ValueFrom: &corev1.EnvVarSource{SecretKeyRef: &corev1.SecretKeySelector{
					LocalObjectReference: corev1.LocalObjectReference{Name: objectName(t.ID)}, Key: "password",
				}}}},
				Ports:        []corev1.ContainerPort{{ContainerPort: 6379}},
				VolumeMounts: []corev1.VolumeMount{{Name: "data", MountPath: "/data"}},
				Resources: corev1.ResourceRequirements{
					Requests: corev1.ResourceList{corev1.ResourceMemory: resource.MustParse(mb + "Mi"), corev1.ResourceCPU: resource.MustParse("25m")},
					Limits:   corev1.ResourceList{corev1.ResourceMemory: limit},
				},
			}},
		},
	}
}

// waitForPong polls until the server has finished loading and answers PING.
func waitForPong(ctx context.Context, ip, password string, within time.Duration) error {
	deadline := time.Now().Add(within)
	var last error
	for time.Now().Before(deadline) {
		c, err := dialAuthed(net.JoinHostPort(ip, "6379"), password)
		if err == nil {
			reply, err := command(c, "PING")
			c.Close()
			if err == nil && reply == "+PONG" {
				return nil
			}
			last = fmt.Errorf("ping: %q %v", reply, err)
		} else {
			last = err
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(50 * time.Millisecond):
		}
	}
	return fmt.Errorf("valkey did not answer: %v", last)
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
