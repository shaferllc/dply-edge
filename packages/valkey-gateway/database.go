package main

import (
	"bufio"
	"bytes"
	"context"
	"crypto/tls"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
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
}

var dbPorts = map[string]string{
	"postgres": "5432",
}

func isDatabase(engine string) bool { _, ok := dbPorts[engine]; return ok }

func dbPodName(id string) string { return "db-" + id }

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
			g.pipeDatabase(tc, id, func(msg string) { writePgError(tc, "57P03", msg) })
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
func (g *gateway) pipeDatabase(client net.Conn, id string, fail func(string)) {
	started := time.Now()
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
	return ip, nil
}

func (g *gateway) sleepDatabase(ctx context.Context, t tenant) error {
	s := g.state(t.ID)
	s.mu.Lock()
	defer s.mu.Unlock()
	for c := range s.conns {
		_ = c.Close()
	}
	pod, ok := g.databasePod(ctx, t.ID)
	if !ok {
		// The pod is gone (node replaced): close the awake stretch anyway, or
		// it would keep counting as awake until the next sleep.
		s.ip = ""
		g.markAsleep(ctx, t.ID)
		return nil
	}
	if err := g.agent(pod.Status.PodIP, "/stop", nil); err != nil {
		return err
	}
	s.ip = ""
	if err := g.resizeDatabase(ctx, pod, t, false); err != nil {
		log.Printf("tenant %s: shrink failed: %v", t.ID, err)
	}
	g.markAsleep(ctx, t.ID)
	log.Printf("tenant %s: asleep", t.ID)
	return nil
}

func (g *gateway) databasePod(ctx context.Context, id string) (*corev1.Pod, bool) {
	p, err := g.kube.CoreV1().Pods(g.cfg.namespace).Get(ctx, dbPodName(id), metav1.GetOptions{})
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
	pvcs := g.kube.CoreV1().PersistentVolumeClaims(g.cfg.namespace)
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
	pods := g.kube.CoreV1().Pods(g.cfg.namespace)
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

func (g *gateway) databasePodSpec(t tenant) *corev1.Pod {
	uid := int64(70) // postgres in the alpine image
	grace := int64(30)
	return &corev1.Pod{
		ObjectMeta: metav1.ObjectMeta{
			Name:   dbPodName(t.ID),
			Labels: map[string]string{"app": "dply-db-pod", "engine": t.Engine, "tenant": t.ID},
		},
		Spec: corev1.PodSpec{
			RestartPolicy:                 corev1.RestartPolicyAlways,
			TerminationGracePeriodSeconds: &grace, // dbagent stops the database cleanly on SIGTERM
			AutomountServiceAccountToken:  new(bool),
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
				Env: []corev1.EnvVar{{Name: "AGENT_TOKEN", ValueFrom: &corev1.EnvVarSource{SecretKeyRef: &corev1.SecretKeySelector{
					LocalObjectReference: corev1.LocalObjectReference{Name: g.cfg.adminSecret}, Key: "password",
				}}}},
				Ports:        []corev1.ContainerPort{{ContainerPort: int32(atoi(dbPorts[t.Engine]))}, {ContainerPort: 7000}},
				VolumeMounts: []corev1.VolumeMount{{Name: "data", MountPath: "/data"}},
				// Memory is resized in place on wake and sleep; no restart.
				ResizePolicy: []corev1.ContainerResizePolicy{{ResourceName: corev1.ResourceMemory, RestartPolicy: corev1.NotRequired}},
				Resources:    databaseResources(t, true),
			}},
		},
	}
}

// databaseResources is what the pod reserves. Parked, only the request
// shrinks: that frees the node's room for awake databases. The limit stays,
// because the kubelet will not set a limit below current use, and a stopped
// database still has its data files in (reclaimable) page cache.
func databaseResources(t tenant, awake bool) corev1.ResourceRequirements {
	mb := resource.MustParse(strconv.Itoa(t.MemoryMB) + "Mi")
	ask := mb
	if !awake {
		ask = resource.MustParse(parkedAsk)
	}
	return corev1.ResourceRequirements{
		Requests: corev1.ResourceList{corev1.ResourceMemory: ask, corev1.ResourceCPU: resource.MustParse("10m")},
		Limits:   corev1.ResourceList{corev1.ResourceMemory: mb},
	}
}

// resizeDatabase changes the pod's memory in place (Kubernetes 1.33+).
func (g *gateway) resizeDatabase(ctx context.Context, pod *corev1.Pod, t tenant, awake bool) error {
	want := databaseResources(t, awake)
	haveAsk := pod.Spec.Containers[0].Resources.Requests[corev1.ResourceMemory]
	haveLimit := pod.Spec.Containers[0].Resources.Limits[corev1.ResourceMemory]
	if haveAsk.Cmp(want.Requests[corev1.ResourceMemory]) == 0 && haveLimit.Cmp(want.Limits[corev1.ResourceMemory]) == 0 {
		return nil
	}
	patch, _ := json.Marshal(map[string]any{"spec": map[string]any{"containers": []map[string]any{{"name": "db", "resources": want}}}})
	_, err := g.kube.CoreV1().Pods(g.cfg.namespace).Patch(ctx, pod.Name, types.StrategicMergePatchType, patch, metav1.PatchOptions{}, "resize")
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
	var payload io.Reader
	if body != nil {
		b, _ := json.Marshal(body)
		payload = bytes.NewReader(b)
	}
	req, _ := http.NewRequest(http.MethodPost, "http://"+net.JoinHostPort(ip, agentPort)+path, payload)
	req.Header.Set("Authorization", "Bearer "+g.cfg.adminPassword)
	resp, err := (&http.Client{Timeout: 90 * time.Second}).Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 300 {
		msg, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("agent %s: %s", path, bytes.TrimSpace(msg))
	}
	return nil
}

func (g *gateway) deleteDatabase(ctx context.Context, id string) {
	zero := int64(0)
	_ = g.kube.CoreV1().Pods(g.cfg.namespace).Delete(ctx, dbPodName(id), metav1.DeleteOptions{GracePeriodSeconds: &zero})
	_ = g.kube.CoreV1().PersistentVolumeClaims(g.cfg.namespace).Delete(ctx, dbPodName(id), metav1.DeleteOptions{})
}

// growDatabaseVolume raises the volume's size request. DigitalOcean block
// storage expands online and the CSI driver grows the filesystem, so the
// database keeps running.
func (g *gateway) growDatabaseVolume(ctx context.Context, id string, diskGB int) error {
	patch := fmt.Sprintf(`{"spec":{"resources":{"requests":{"storage":"%dGi"}}}}`, diskGB)
	_, err := g.kube.CoreV1().PersistentVolumeClaims(g.cfg.namespace).Patch(ctx, dbPodName(id), types.MergePatchType, []byte(patch), metav1.PatchOptions{})
	if apierrors.IsNotFound(err) {
		return nil // not created yet; the first wake creates it at the new size
	}
	return err
}

func ptr[T any](v T) *T { return &v }
