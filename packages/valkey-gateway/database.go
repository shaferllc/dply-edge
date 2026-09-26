package main

import (
	"bufio"
	"bytes"
	"context"
	"crypto/tls"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"strconv"
	"time"

	corev1 "k8s.io/api/core/v1"
	apierrors "k8s.io/apimachinery/pkg/api/errors"
	"k8s.io/apimachinery/pkg/api/resource"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/types"
)

// Databases (ruling r-r5h70qp951w28qrh): one pod per tenant, created once,
// data on a node-local volume so the pod is pinned to its node. Sleep stops
// the database process through dbagent and shrinks the pod's memory in place;
// wake grows it back and starts the process. The pod itself stays.

const (
	agentPort = "7000"
	parkedAsk = "16Mi"
)

var dbImages = map[string]string{
	"postgres": env("POSTGRES_IMAGE", "dply/postgres:17"),
	"mongodb":  env("MONGO_IMAGE", "dply/mongodb:7"),
	"mysql":    env("MYSQL_IMAGE", "dply/mysql:8"),
}

var dbPorts = map[string]string{
	"postgres": "5432",
	"mongodb":  "27017",
	"mysql":    "3306",
}

func isDatabase(engine string) bool { _, ok := dbPorts[engine]; return ok }

func dbPodName(id string) string { return "db-" + id }

// wakeDeadline is how long a client connection may wait for its database to
// wake or, the first time, to be built (volume, image, init).
const wakeDeadline = 5 * time.Minute

// ---- Postgres listener ----

const (
	pgSSLRequest = 80877103
	pgGSSRequest = 80877104
)

// servePostgres accepts the SSLRequest dance (and PG17 direct TLS), reads the
// SNI name, wakes the tenant, and pipes the decrypted stream to its pod.
func (g *gateway) servePostgres(certs *certReloader) {
	addr := env("POSTGRES_ADDR", ":5432")
	ln, err := net.Listen("tcp", addr)
	if err != nil {
		log.Fatal(err)
	}
	log.Printf("postgres proxy on %s", addr)
	// GetCertificate, not a fixed cert: renewals (and new names on the
	// certificate) must reach this listener too.
	cfg := &tls.Config{GetCertificate: certs.get, MinVersion: tls.VersionTLS12, NextProtos: []string{"postgresql"}}
	for {
		c, err := ln.Accept()
		if err != nil {
			continue
		}
		go func() {
			defer c.Close()
			_ = c.SetDeadline(time.Now().Add(15 * time.Second))
			br := bufio.NewReader(c)
			for {
				first, err := br.Peek(1)
				if err != nil {
					return
				}
				if first[0] == 0x16 { // direct TLS (sslnegotiation=direct)
					break
				}
				head := make([]byte, 8)
				if _, err := io.ReadFull(br, head); err != nil {
					return
				}
				code := binary.BigEndian.Uint32(head[4:])
				if code == pgGSSRequest {
					_, _ = c.Write([]byte("N")) // no GSS; the client asks for TLS next
					continue
				}
				if code != pgSSLRequest {
					writePgError(c, "08P01", "dply databases need TLS (sslmode=require)")
					return
				}
				_, _ = c.Write([]byte("S"))
				break
			}
			tc := tls.Server(&bufferedConn{Conn: c, r: br}, cfg)
			if err := tc.Handshake(); err != nil {
				return
			}
			id, ok := tenantFromName(tc.ConnectionState().ServerName, g.cfg.dbDomain)
			if !ok {
				writePgError(tc, "08004", "unknown database host; connect by its dply hostname")
				return
			}
			g.pipeDatabase(tc, id, "postgres", func(msg string) { writePgError(tc, "57P03", msg) })
		}()
	}
}

type bufferedConn struct {
	net.Conn
	r *bufio.Reader
}

func (b *bufferedConn) Read(p []byte) (int, error) { return b.r.Read(p) }

func writePgError(w io.Writer, code, msg string) {
	var body bytes.Buffer
	body.WriteString("SFATAL\x00")
	body.WriteString("C" + code + "\x00")
	body.WriteString("M" + msg + "\x00\x00")
	head := make([]byte, 5)
	head[0] = 'E'
	binary.BigEndian.PutUint32(head[1:], uint32(body.Len()+4))
	_, _ = w.Write(append(head, body.Bytes()...))
}

// pipeDatabase wakes the tenant and copies bytes both ways, like Valkey.
// serveMongo: MongoDB clients with tls=true open TLS straight away and send
// the hostname as SNI, so this is the Valkey pattern on another port: read
// the name, wake the tenant, pipe the decrypted stream.
func (g *gateway) serveMongo(certs *certReloader) {
	ln, err := tls.Listen("tcp", env("MONGO_ADDR", ":27017"), &tls.Config{GetCertificate: certs.get, MinVersion: tls.VersionTLS12})
	if err != nil {
		log.Fatal(err)
	}
	log.Printf("mongodb proxy on %s", env("MONGO_ADDR", ":27017"))
	for {
		conn, err := ln.Accept()
		if err != nil {
			continue
		}
		go func() {
			tc := conn.(*tls.Conn)
			defer tc.Close()
			_ = tc.SetDeadline(time.Now().Add(15 * time.Second))
			if err := tc.Handshake(); err != nil {
				return
			}
			id, ok := tenantFromName(tc.ConnectionState().ServerName, g.cfg.dbDomain)
			if !ok {
				return // a MongoDB client has no error channel before its handshake
			}
			g.pipeDatabase(tc, id, "mongodb", func(string) {})
		}()
	}
}

// pipeDatabase wakes the tenant and pipes the client to it. engine is the
// protocol of the port the client came in on; a tenant of another engine is
// refused before anything wakes.
func (g *gateway) pipeDatabase(client net.Conn, id, engine string, fail func(string)) {
	if t, err := g.getTenantRecord(context.Background(), id); err != nil || t.Engine != engine {
		fail("no " + engine + " database at this address")
		return
	}
	started := time.Now()
	// A wake (or a brand-new database's first build) can outlast the
	// listener's handshake deadline; the client just waits.
	_ = client.SetDeadline(time.Now().Add(wakeDeadline))
	ip, err := g.wakeDatabase(context.Background(), id)
	if err != nil {
		log.Printf("tenant %s: wake failed: %v", id, err)
		fail("this database could not start")
		return
	}
	if waited := time.Since(started); waited > 100*time.Millisecond {
		log.Printf("tenant %s: woke in %s", id, waited.Round(time.Millisecond))
	}
	t, err := g.getTenantRecord(context.Background(), id)
	if err != nil {
		return
	}
	upstream, err := net.DialTimeout("tcp", net.JoinHostPort(ip, dbPorts[t.Engine]), 5*time.Second)
	if err != nil {
		g.forget(id)
		fail("this database is not reachable")
		return
	}
	defer upstream.Close()
	_ = client.SetDeadline(time.Time{})

	s := g.state(id)
	s.mu.Lock()
	s.conns[client] = struct{}{}
	s.lastActivity = time.Now()
	s.mu.Unlock()
	defer func() {
		s.mu.Lock()
		delete(s.conns, client)
		s.mu.Unlock()
	}()
	done := make(chan struct{}, 2)
	go func() { copyTouching(upstream, client, func() { g.touch(id) }); done <- struct{}{} }()
	go func() { copyTouching(client, upstream, nil); done <- struct{}{} }()
	<-done
}

// ---- lifecycle ----

func (g *gateway) wakeDatabase(ctx context.Context, id string) (string, error) {
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
	if !isDatabase(t.Engine) {
		return "", fmt.Errorf("tenant %s is not a database", id)
	}
	step := time.Now()
	lap := func(name string) {
		if debugTiming() {
			log.Printf("tenant %s: %s %s", id, name, time.Since(step).Round(time.Millisecond))
		}
		step = time.Now()
	}

	pod, err := g.ensureDatabasePod(ctx, *t)
	if err != nil {
		return "", err
	}
	ip := pod.Status.PodIP
	lap("pod")
	if err := g.resizeDatabase(ctx, pod, *t, true); err != nil {
		return "", err
	}
	lap("resize")
	if err := g.agent(ip, "/start", nil); err != nil {
		return "", err
	}
	lap("start")
	if err := g.agent(ip, "/tenant", map[string]string{"password": t.Password}); err != nil {
		return "", err
	}
	lap("login")
	s.ip = ip
	s.lastActivity = time.Now()
	go g.markAwake(context.Background(), id)
	go g.setEvictable(context.Background(), id, false)
	return ip, nil
}

// setEvictable marks a database pod for the cluster autoscaler: not safe to
// evict while awake (a scale-down would cut queries and restores), safe while
// parked so an emptied node can still be removed.
func (g *gateway) setEvictable(ctx context.Context, id string, ok bool) {
	patch := fmt.Sprintf(`{"metadata":{"annotations":{"cluster-autoscaler.kubernetes.io/safe-to-evict":"%t"}}}`, ok)
	if _, err := g.kube.CoreV1().Pods(g.cfg.dbNamespace).Patch(ctx, dbPodName(id), types.MergePatchType, []byte(patch), metav1.PatchOptions{}); err != nil && !apierrors.IsNotFound(err) {
		log.Printf("tenant %s: safe-to-evict=%t: %v", id, ok, err)
	}
}

// errBusy: an idle sleep was refused because a backup or an app query is
// running (dbagent's /stop?if_idle=1 answered 409).
var errBusy = errors.New("busy")

// sleepDatabaseSoon sleeps the database now, or, if it is busy (a backup or a
// query), once it is not: it retries each minute in the background and forces
// the stop after 3 hours. Used for requested sleeps (resize, new password,
// API), which only need to happen before the next wake.
func (g *gateway) sleepDatabaseSoon(ctx context.Context, t tenant) error {
	err := g.sleepDatabase(ctx, t, true)
	if !errors.Is(err, errBusy) {
		return err
	}
	log.Printf("tenant %s: sleeping once it is not busy (%v)", t.ID, err)
	go func() {
		for deadline := time.Now().Add(3 * time.Hour); time.Now().Before(deadline); {
			time.Sleep(time.Minute)
			if err := g.sleepDatabase(context.Background(), t, true); !errors.Is(err, errBusy) {
				if err != nil {
					log.Printf("tenant %s: sleep failed: %v", t.ID, err)
				}
				return
			}
		}
		if err := g.sleepDatabase(context.Background(), t, false); err != nil {
			log.Printf("tenant %s: sleep failed: %v", t.ID, err)
		}
	}()
	return nil
}

// sleepDatabase stops the database. idle is refused (errBusy) while a backup
// or an app query runs; otherwise it stops regardless.
func (g *gateway) sleepDatabase(ctx context.Context, t tenant, idle bool) error {
	s := g.state(t.ID)
	s.mu.Lock()
	defer s.mu.Unlock()
	pod, ok := g.databasePod(ctx, t.ID)
	if ok && idle {
		// Ask first, before dropping connections: the answer may be "not now".
		if err := g.agent(pod.Status.PodIP, "/stop?if_idle=1", nil); err != nil {
			return err
		}
	}
	for c := range s.conns {
		_ = c.Close()
	}
	if !ok {
		// The pod is gone (node replaced): close the awake stretch anyway, or
		// it would keep counting as awake until the next sleep.
		s.ip = ""
		g.markAsleep(ctx, t.ID)
		return nil
	}
	if !idle {
		if err := g.agent(pod.Status.PodIP, "/stop", nil); err != nil {
			return err
		}
	}
	s.ip = ""
	if err := g.resizeDatabase(ctx, pod, t, false); err != nil {
		log.Printf("tenant %s: shrink failed: %v", t.ID, err)
	}
	g.markAsleep(ctx, t.ID)
	g.setEvictable(ctx, t.ID, true)
	log.Printf("tenant %s: asleep", t.ID)
	return nil
}

// databaseParked reports whether the pod is parked (asleep): its memory
// request is shrunk to parkedAsk.
func databaseParked(p *corev1.Pod) bool {
	ask := p.Spec.Containers[0].Resources.Requests[corev1.ResourceMemory]
	return ask.Cmp(resource.MustParse(parkedAsk)) == 0
}

func (g *gateway) databasePod(ctx context.Context, id string) (*corev1.Pod, bool) {
	p, err := g.kube.CoreV1().Pods(g.cfg.dbNamespace).Get(ctx, dbPodName(id), metav1.GetOptions{})
	if err != nil || p.DeletionTimestamp != nil || p.Status.Phase != corev1.PodRunning || p.Status.PodIP == "" {
		return nil, false
	}
	return p, true
}

// ensureDatabasePod creates the tenant's pod and volume the first time, then
// waits for dbagent to answer.
func (g *gateway) ensureDatabasePod(ctx context.Context, t tenant) (*corev1.Pod, error) {
	if p, ok := g.databasePod(ctx, t.ID); ok {
		return p, nil
	}
	pvcs := g.kube.CoreV1().PersistentVolumeClaims(g.cfg.dbNamespace)
	pvc := &corev1.PersistentVolumeClaim{
		ObjectMeta: metav1.ObjectMeta{Name: dbPodName(t.ID), Labels: map[string]string{"app": "dply-db", "tenant": t.ID}},
		Spec: corev1.PersistentVolumeClaimSpec{
			AccessModes:      []corev1.PersistentVolumeAccessMode{corev1.ReadWriteOnce},
			StorageClassName: ptr(env("DB_STORAGE_CLASS", "local-path")),
			Resources: corev1.VolumeResourceRequirements{Requests: corev1.ResourceList{
				corev1.ResourceStorage: resource.MustParse(strconv.Itoa(max(t.DiskGB, 1)) + "Gi"),
			}},
		},
	}
	if _, err := pvcs.Create(ctx, pvc, metav1.CreateOptions{}); err != nil && !apierrors.IsAlreadyExists(err) {
		return nil, err
	}
	pods := g.kube.CoreV1().Pods(g.cfg.dbNamespace)
	g.clearStuckDatabasePod(ctx, t.ID)
	if _, err := pods.Create(ctx, g.databasePodSpec(t), metav1.CreateOptions{}); err != nil && !apierrors.IsAlreadyExists(err) {
		return nil, err
	}
	deadline := time.Now().Add(120 * time.Second)
	for time.Now().Before(deadline) {
		if p, ok := g.databasePod(ctx, t.ID); ok && g.agentUp(p.Status.PodIP) {
			return p, nil
		}
		time.Sleep(100 * time.Millisecond)
	}
	return nil, fmt.Errorf("database pod did not start in time")
}

// clearStuckDatabasePod force-deletes a pod left behind by a dead node. The
// node never confirms the delete, so the pod stays Terminating and holds the
// fixed name: without this every wake fails "already exists" until someone
// deletes it by hand. Forcing it also lets the volume detach and move.
func (g *gateway) clearStuckDatabasePod(ctx context.Context, id string) {
	pods := g.kube.CoreV1().Pods(g.cfg.dbNamespace)
	p, err := pods.Get(ctx, dbPodName(id), metav1.GetOptions{})
	if err != nil || p.DeletionTimestamp == nil {
		return
	}
	grace := int64(30)
	if p.DeletionGracePeriodSeconds != nil {
		grace = *p.DeletionGracePeriodSeconds
	}
	if time.Since(p.DeletionTimestamp.Time) < time.Duration(grace+15)*time.Second {
		return
	}
	zero := int64(0)
	log.Printf("tenant %s: pod stuck terminating on %s since %s; forcing delete", id, p.Spec.NodeName, p.DeletionTimestamp.Format(time.RFC3339))
	_ = pods.Delete(ctx, p.Name, metav1.DeleteOptions{GracePeriodSeconds: &zero})
}

// databasePlacement keeps databases on their own pool (DB_NODE_POOL, tainted
// dply.dev/db), off the cache nodes. Evicting 30 s after its node stops
// answering (default 300 s) starts recovery elsewhere sooner.
func (g *gateway) databasePlacement() (map[string]string, []corev1.Toleration) {
	gone := int64(30)
	tolerations := []corev1.Toleration{
		{Key: "node.kubernetes.io/not-ready", Operator: corev1.TolerationOpExists, Effect: corev1.TaintEffectNoExecute, TolerationSeconds: &gone},
		{Key: "node.kubernetes.io/unreachable", Operator: corev1.TolerationOpExists, Effect: corev1.TaintEffectNoExecute, TolerationSeconds: &gone},
	}
	if g.cfg.dbNodePool == "" {
		return nil, tolerations
	}
	return map[string]string{nodePoolKey: g.cfg.dbNodePool},
		append(tolerations, corev1.Toleration{Key: dbTaintKey, Operator: corev1.TolerationOpEqual, Value: "true", Effect: corev1.TaintEffectNoSchedule})
}

func (g *gateway) databasePodSpec(t tenant) *corev1.Pod {
	uid := int64(999) // postgres in the Debian image (dbagent/Dockerfile.postgres)
	grace := int64(30)
	nodeSelector, tolerations := g.databasePlacement()
	return &corev1.Pod{
		ObjectMeta: metav1.ObjectMeta{
			Name:   dbPodName(t.ID),
			Labels: map[string]string{"app": "dply-db-pod", "engine": t.Engine, "tenant": t.ID},
		},
		Spec: corev1.PodSpec{
			NodeSelector:                  nodeSelector,
			Tolerations:                   tolerations,
			RestartPolicy:                 corev1.RestartPolicyAlways,
			TerminationGracePeriodSeconds: &grace, // dbagent stops the database cleanly on SIGTERM
			AutomountServiceAccountToken:  new(bool),
			// The database's public name points at itself inside the pod: a
			// MongoDB replica set member must recognise its own host name.
			HostAliases: []corev1.HostAlias{{IP: "127.0.0.1", Hostnames: []string{t.ID + "." + g.cfg.dbDomain}}},
			// OnRootMismatch: only fix ownership when the volume root doesn't match.
			// The default re-chmods every file on every attach (slow on big
			// volumes), which also made a moved database's data directory
			// group-readable and Postgres refused to start.
			SecurityContext: &corev1.PodSecurityContext{RunAsUser: &uid, RunAsGroup: &uid, FSGroup: &uid, FSGroupChangePolicy: ptr(corev1.FSGroupChangeOnRootMismatch)},
			Volumes: []corev1.Volume{{Name: "data", VolumeSource: corev1.VolumeSource{
				PersistentVolumeClaim: &corev1.PersistentVolumeClaimVolumeSource{ClaimName: dbPodName(t.ID)},
			}}},
			Containers: []corev1.Container{{
				Name:            "db",
				Image:           dbImages[t.Engine],
				ImagePullPolicy: corev1.PullIfNotPresent,
				Env: append([]corev1.EnvVar{{Name: "AGENT_TOKEN", ValueFrom: &corev1.EnvVarSource{SecretKeyRef: &corev1.SecretKeySelector{
					LocalObjectReference: corev1.LocalObjectReference{Name: g.cfg.adminSecret}, Key: "password",
				}}}, {Name: "DB_PUBLIC_HOST", Value: t.ID + "." + g.cfg.dbDomain}}, backupEnv(t)...),
				Ports:        []corev1.ContainerPort{{ContainerPort: int32(atoi(dbPorts[t.Engine]))}, {ContainerPort: 7000}},
				VolumeMounts: []corev1.VolumeMount{{Name: "data", MountPath: "/data"}},
				// Memory and CPU are resized in place on wake and sleep; no restart.
				ResizePolicy: []corev1.ContainerResizePolicy{{ResourceName: corev1.ResourceMemory, RestartPolicy: corev1.NotRequired}, {ResourceName: corev1.ResourceCPU, RestartPolicy: corev1.NotRequired}},
				Resources:    databaseResources(t, true),
			}},
		},
	}
}

// databaseResources is what the pod reserves. Parked, only the request
// shrinks: that frees the node's room for awake databases. The limit stays,
// because the kubelet will not set a limit below current use, and a stopped
// database still has its data files in (reclaimable) page cache.
//
// CPU follows the same rule: an awake database is guaranteed a share in step
// with its size, so one busy neighbour on the node cannot starve it; parked,
// it drops to almost nothing. No CPU limit, so it still bursts into idle CPU.
func databaseResources(t tenant, awake bool) corev1.ResourceRequirements {
	mb := resource.MustParse(strconv.Itoa(t.MemoryMB) + "Mi")
	ask := mb
	cpu := resource.MustParse(databaseCPU(t.MemoryMB))
	if !awake {
		ask = resource.MustParse(parkedAsk)
		cpu = resource.MustParse("10m")
	}
	return corev1.ResourceRequirements{
		Requests: corev1.ResourceList{corev1.ResourceMemory: ask, corev1.ResourceCPU: cpu},
		Limits:   corev1.ResourceList{corev1.ResourceMemory: mb},
	}
}

// databaseCPU is an awake database's guaranteed CPU: 250m per GB of memory,
// at least 100m, at most one core.
func databaseCPU(memoryMB int) string {
	return strconv.Itoa(min(1000, max(100, memoryMB*250/1024))) + "m"
}

// resizeDatabase changes the pod's memory and CPU in place (Kubernetes 1.33+).
func (g *gateway) resizeDatabase(ctx context.Context, pod *corev1.Pod, t tenant, awake bool) error {
	want := databaseResources(t, awake)
	haveAsk := pod.Spec.Containers[0].Resources.Requests[corev1.ResourceMemory]
	haveCPU := pod.Spec.Containers[0].Resources.Requests[corev1.ResourceCPU]
	haveLimit := pod.Spec.Containers[0].Resources.Limits[corev1.ResourceMemory]
	if haveAsk.Cmp(want.Requests[corev1.ResourceMemory]) == 0 && haveCPU.Cmp(want.Requests[corev1.ResourceCPU]) == 0 && haveLimit.Cmp(want.Limits[corev1.ResourceMemory]) == 0 {
		return nil
	}
	patch, _ := json.Marshal(map[string]any{"spec": map[string]any{"containers": []map[string]any{{"name": "db", "resources": want}}}})
	_, err := g.kube.CoreV1().Pods(g.cfg.dbNamespace).Patch(ctx, pod.Name, types.StrategicMergePatchType, patch, metav1.PatchOptions{}, "resize")
	return err
}

func (g *gateway) agentUp(ip string) bool {
	c := http.Client{Timeout: time.Second}
	resp, err := c.Get("http://" + net.JoinHostPort(ip, agentPort) + "/healthz")
	if err != nil {
		return false
	}
	resp.Body.Close()
	return resp.StatusCode == http.StatusOK
}

func (g *gateway) agent(ip, path string, body any) error {
	return g.agentWait(ip, path, body, 90*time.Second)
}

func (g *gateway) agentWait(ip, path string, body any, timeout time.Duration) error {
	var payload io.Reader
	if body != nil {
		b, _ := json.Marshal(body)
		payload = bytes.NewReader(b)
	}
	req, _ := http.NewRequest(http.MethodPost, "http://"+net.JoinHostPort(ip, agentPort)+path, payload)
	req.Header.Set("Authorization", "Bearer "+g.cfg.adminPassword)
	resp, err := (&http.Client{Timeout: timeout}).Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode == http.StatusConflict {
		msg, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("%w (%s)", errBusy, bytes.TrimSpace(msg))
	}
	if resp.StatusCode >= 300 {
		msg, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("agent %s: %s", path, bytes.TrimSpace(msg))
	}
	return nil
}

func (g *gateway) deleteDatabase(ctx context.Context, id string) {
	zero := int64(0)
	_ = g.kube.CoreV1().Pods(g.cfg.dbNamespace).Delete(ctx, dbPodName(id), metav1.DeleteOptions{GracePeriodSeconds: &zero})
	_ = g.kube.CoreV1().PersistentVolumeClaims(g.cfg.dbNamespace).Delete(ctx, dbPodName(id), metav1.DeleteOptions{})
	// Its backups go with it (every engine's, under tenants/{id}/).
	go func() {
		if err := g.store.removePrefix(context.Background(), "tenants/"+id+"/"); err != nil {
			log.Printf("tenant %s: removing backups: %v", id, err)
		}
	}()
}

// ---- backups (dbagent in the database pod, ruling r-67chv2jdx2ha025q) ----

// backupPrefix: Postgres wal-g under tenants/{id}/pg, MongoDB and MySQL
// daily dumps under tenants/{id}/{engine}.
func backupPrefix(t tenant) string {
	if t.Engine == "postgres" {
		return "tenants/" + t.ID + "/pg"
	}
	return "tenants/" + t.ID + "/" + t.Engine
}

// backupEnv points a database pod's backups at backupPrefix in the same
// bucket as Valkey snapshots. Keys come from DB_BACKUP_SECRET (the R2 secret)
// by reference, never inlined. Unset (local), there are no backups.
func backupEnv(t tenant) []corev1.EnvVar {
	secret, bucket := os.Getenv("DB_BACKUP_SECRET"), os.Getenv("S3_BUCKET")
	if secret == "" || bucket == "" {
		return nil
	}
	fromSecret := func(name, key string) corev1.EnvVar {
		return corev1.EnvVar{Name: name, ValueFrom: &corev1.EnvVarSource{SecretKeyRef: &corev1.SecretKeySelector{
			LocalObjectReference: corev1.LocalObjectReference{Name: secret}, Key: key,
		}}}
	}
	return []corev1.EnvVar{
		{Name: "WALG_S3_PREFIX", Value: "s3://" + bucket + "/" + backupPrefix(t)},
		{Name: "AWS_ENDPOINT", Value: os.Getenv("S3_ENDPOINT")},
		{Name: "AWS_REGION", Value: env("S3_REGION", "auto")},
		{Name: "AWS_S3_FORCE_PATH_STYLE", Value: "true"},
		fromSecret("AWS_ACCESS_KEY_ID", "access-key"),
		fromSecret("AWS_SECRET_ACCESS_KEY", "secret-key"),
	}
}

// restoreDatabase wakes the database, has dbagent restore it (to target, an
// RFC3339 time, or the latest point when empty), then re-applies the app's
// current password, since the restored data carries the one from back then.
func (g *gateway) restoreDatabase(ctx context.Context, t tenant, target string) error {
	ip, err := g.wakeDatabase(ctx, t.ID)
	if err != nil {
		return err
	}
	s := g.state(t.ID)
	s.mu.Lock()
	defer s.mu.Unlock()
	for c := range s.conns {
		_ = c.Close()
	}
	if err := g.agentWait(ip, "/restore", map[string]string{"target_time": target}, 15*time.Minute); err != nil {
		return err
	}
	return g.agent(ip, "/tenant", map[string]string{"password": t.Password})
}

// growDatabaseVolume raises the volume's size request. DigitalOcean block
// storage expands online and the CSI driver grows the filesystem, so the
// database keeps running.
func (g *gateway) growDatabaseVolume(ctx context.Context, id string, diskGB int) error {
	patch := fmt.Sprintf(`{"spec":{"resources":{"requests":{"storage":"%dGi"}}}}`, diskGB)
	_, err := g.kube.CoreV1().PersistentVolumeClaims(g.cfg.dbNamespace).Patch(ctx, dbPodName(id), types.MergePatchType, []byte(patch), metav1.PatchOptions{})
	if apierrors.IsNotFound(err) {
		return nil // not created yet; the first wake creates it at the new size
	}
	return err
}

func ptr[T any](v T) *T { return &v }
