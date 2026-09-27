package main

import (
	"bytes"
	"context"
	"io"
	"log"
	"net"
	"net/http"
	"path"
	"regexp"
	"strings"
	"time"

	"github.com/minio/minio-go/v7"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
)

// Database insights and actions (dbagent/insights.go), relayed for the dply
// app. The cached read never wakes a database: the agent answers from the
// snapshot it wrote before the last stop, and the pod stays up while it
// sleeps. Actions wake it, like a connection would.

var agentActions = map[string]time.Duration{
	"queries-reset": 30 * time.Second,
	"cancel":        30 * time.Second,
	"extension":     2 * time.Minute,
	"readonly":      30 * time.Second,
	"query":         30 * time.Second,
	"export":        time.Hour,
	"import":        time.Hour,
}

func (g *gateway) databaseInsights(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if t, err := g.getTenantRecord(r.Context(), id); err != nil || !isDatabase(t.Engine) {
		http.Error(w, "not a database", http.StatusNotFound)
		return
	}
	cached := r.URL.Query().Get("cached") == "1"
	var ip string
	if cached {
		pod, ok := g.databasePod(r.Context(), id)
		if !ok {
			writeJSON(w, http.StatusOK, map[string]any{"awake": false})
			return
		}
		ip = pod.Status.PodIP
	} else {
		var err error
		if ip, err = g.wakeDatabase(r.Context(), id); err != nil {
			http.Error(w, "this database could not start: "+err.Error(), http.StatusServiceUnavailable)
			return
		}
		g.touch(id)
	}
	path := "/insights"
	if cached {
		path += "?cached=1"
	}
	g.relayAgent(w, r.Context(), http.MethodGet, ip, path, nil, 30*time.Second)
}

func (g *gateway) databaseAction(w http.ResponseWriter, r *http.Request) {
	id, name := r.PathValue("id"), r.PathValue("name")
	timeout, ok := agentActions[name]
	if !ok {
		http.Error(w, "unknown action", http.StatusNotFound)
		return
	}
	if t, err := g.getTenantRecord(r.Context(), id); err != nil || !isDatabase(t.Engine) {
		http.Error(w, "not a database", http.StatusNotFound)
		return
	}
	body, _ := io.ReadAll(io.LimitReader(r.Body, 1<<20))
	ip, err := g.wakeDatabase(r.Context(), id)
	if err != nil {
		http.Error(w, "this database could not start: "+err.Error(), http.StatusServiceUnavailable)
		return
	}
	g.touch(id)
	// An export or import outlives the request's own deadline on purpose.
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	g.relayAgent(w, ctx, http.MethodPost, ip, "/action/"+name, body, timeout)
	g.touch(id)
}

// Exports and imports live beside the backups, under tenants/{id}/exports/
// and tenants/{id}/imports/. The app has no keys to the bucket, so the
// gateway lists exports with download links and signs an upload for an
// import. Keys are built here from the tenant id, never taken from a caller.

var transferFile = regexp.MustCompile(`^[A-Za-z0-9._-]{1,120}$`)

const transferLinkTTL = time.Hour

func (g *gateway) databaseExports(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if t, err := g.getTenantRecord(r.Context(), id); err != nil || !isDatabase(t.Engine) {
		http.Error(w, "not a database", http.StatusNotFound)
		return
	}
	out := []map[string]any{}
	prefix := "tenants/" + id + "/exports/"
	for obj := range g.store.client.ListObjects(r.Context(), g.store.bucket, minio.ListObjectsOptions{Prefix: prefix, Recursive: true}) {
		if obj.Err != nil {
			http.Error(w, obj.Err.Error(), http.StatusBadGateway)
			return
		}
		link, err := g.store.client.PresignedGetObject(r.Context(), g.store.bucket, obj.Key, transferLinkTTL, nil)
		if err != nil {
			http.Error(w, err.Error(), http.StatusBadGateway)
			return
		}
		out = append(out, map[string]any{"file": path.Base(obj.Key), "key": obj.Key, "bytes": obj.Size, "at": obj.LastModified.UTC().Format(time.RFC3339), "url": link.String()})
	}
	writeJSON(w, http.StatusOK, map[string]any{"exports": out})
}

// databaseUploadLink signs a PUT for tenants/{id}/imports/{file}.
func (g *gateway) databaseUploadLink(w http.ResponseWriter, r *http.Request) {
	id, file := r.PathValue("id"), r.URL.Query().Get("file")
	if t, err := g.getTenantRecord(r.Context(), id); err != nil || !isDatabase(t.Engine) {
		http.Error(w, "not a database", http.StatusNotFound)
		return
	}
	if !transferFile.MatchString(file) || strings.HasPrefix(file, ".") {
		http.Error(w, "file: letters, digits, dot, dash and underscore", http.StatusUnprocessableEntity)
		return
	}
	k := "tenants/" + id + "/imports/" + file
	link, err := g.store.client.PresignedPutObject(r.Context(), g.store.bucket, k, transferLinkTTL)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadGateway)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"key": k, "url": link.String()})
}

func (g *gateway) relayAgent(w http.ResponseWriter, ctx context.Context, method, ip, path string, body []byte, timeout time.Duration) {
	req, _ := http.NewRequestWithContext(ctx, method, "http://"+net.JoinHostPort(ip, agentPort)+path, bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer "+g.cfg.adminPassword)
	resp, err := (&http.Client{Timeout: timeout}).Do(req)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadGateway)
		return
	}
	defer resp.Body.Close()
	w.Header().Set("Content-Type", resp.Header.Get("Content-Type"))
	w.WriteHeader(resp.StatusCode)
	_, _ = io.Copy(w, resp.Body)
}

// rollDatabaseImage moves a parked database onto the current image. Pods are
// created once and kept, so a new agent or engine build only reaches a
// database when its pod is replaced. Done right after a sleep (the process
// is stopped, nothing is connected), and recreated parked in the
// background, so the next wake is the usual process start. The volume stays.
func (g *gateway) rollDatabaseImage(t tenant) {
	ctx := context.Background()
	pod, err := g.kube.CoreV1().Pods(g.cfg.dbNamespace).Get(ctx, dbPodName(t.ID), metav1.GetOptions{})
	if err != nil || pod.DeletionTimestamp != nil || len(pod.Spec.Containers) == 0 || pod.Spec.Containers[0].Image == dbImages[t.Engine] {
		return
	}
	s := g.state(t.ID)
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.ip != "" {
		return // woke up again in the meantime; the next sleep rolls it
	}
	log.Printf("tenant %s: moving to %s", t.ID, dbImages[t.Engine])
	if err := g.kube.CoreV1().Pods(g.cfg.dbNamespace).Delete(ctx, pod.Name, metav1.DeleteOptions{}); err != nil {
		log.Printf("tenant %s: image roll: %v", t.ID, err)
		return
	}
	for deadline := time.Now().Add(2 * time.Minute); time.Now().Before(deadline); time.Sleep(time.Second) {
		if _, err := g.kube.CoreV1().Pods(g.cfg.dbNamespace).Get(ctx, pod.Name, metav1.GetOptions{}); err != nil {
			break
		}
	}
	fresh, err := g.ensureDatabasePod(ctx, t)
	if err != nil {
		log.Printf("tenant %s: image roll: recreate: %v (the next wake retries)", t.ID, err)
		return
	}
	if err := g.resizeDatabase(ctx, fresh, t, false); err != nil {
		log.Printf("tenant %s: image roll: park: %v", t.ID, err)
	}
	g.setEvictable(ctx, t.ID, true)
}
