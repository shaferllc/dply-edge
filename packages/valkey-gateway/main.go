// valkey-gateway runs dply's managed Valkey (ruling r-72p0gkdn9dqwxqha, T-021).
//
// One pod per tenant, each with a hard memory limit. Clients connect with TLS
// to {tenant}.{domain}:6380; the gateway reads the SNI name, wakes the tenant
// if it is asleep (holding the connection meanwhile), and pipes bytes to the
// pod. Flex tenants sleep after an idle time: the gateway pulls an RDB over
// Valkey's own SYNC, stores it in S3 (R2 in production), and deletes the pod.
// The next connection restores it.
//
// Kubernetes is the only state: a tenant is a Secret labelled
// app=dply-valkey; an awake tenant also has a Pod with the same name.
package main

import (
	"context"
	"crypto/subtle"
	"crypto/tls"
	"encoding/json"
	"errors"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"

	corev1 "k8s.io/api/core/v1"
	"k8s.io/client-go/kubernetes"
	"k8s.io/client-go/rest"
)

type config struct {
	adminPassword string
	adminSecret   string
	pool          map[int]int // memory MB -> warm pods kept ready
	namespace     string
	domain        string
	dbDomain      string // databases: {tenant}.{dbDomain}:5432
	image         string
	proxyAddr     string
	apiAddr       string
	apiToken      string
	certFile      string
	keyFile       string
	snapshotAge   time.Duration
}

func env(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func main() {
	cfg := config{
		namespace:     env("NAMESPACE", "dply-valkey"),
		domain:        env("DOMAIN", "cache.dply.local"),
		dbDomain:      env("DB_DOMAIN", env("DOMAIN", "cache.dply.local")),
		image:         env("VALKEY_IMAGE", "valkey/valkey:8-alpine"),
		proxyAddr:     env("PROXY_ADDR", ":6380"),
		apiAddr:       env("API_ADDR", ":8080"),
		apiToken:      strings.TrimSpace(os.Getenv("API_TOKEN")),
		certFile:      env("TLS_CERT", "/tls/tls.crt"),
		keyFile:       env("TLS_KEY", "/tls/tls.key"),
		snapshotAge:   15 * time.Minute,
		adminPassword: strings.TrimSpace(os.Getenv("ADMIN_PASSWORD")),
		adminSecret:   env("ADMIN_SECRET", "valkey-gateway-admin"),
		pool:          parsePool(env("POOL", "250:2")),
	}
	if len(cfg.adminPassword) < 16 {
		log.Fatal("ADMIN_PASSWORD (16+ characters) is required")
	}
	if cfg.apiToken == "" {
		log.Fatal("API_TOKEN is required")
	}

	restCfg, err := rest.InClusterConfig()
	if err != nil {
		log.Fatal(err)
	}
	kube, err := kubernetes.NewForConfig(restCfg)
	if err != nil {
		log.Fatal(err)
	}
	store, err := newSnapshotStore()
	if err != nil {
		log.Fatal(err)
	}

	g := &gateway{cfg: cfg, kube: kube, store: store, tenants: map[string]*tenantState{}}
	go g.reap(context.Background())
	go g.serveAPI()
	g.serveProxy()
}

// tenantState is in-memory only: who is connected and when they last sent bytes.
type tenantState struct {
	mu           sync.Mutex // serialises wake and sleep
	lastActivity time.Time
	lastSnapshot time.Time
	conns        map[net.Conn]struct{}
	restarts     map[string]int32 // container restarts seen, per pod
	ip           string           // set once the pod answered PING; cleared on sleep

}

type gateway struct {
	cfg     config
	kube    *kubernetes.Clientset
	store   *snapshotStore
	mu      sync.Mutex
	tenants map[string]*tenantState
}

func (g *gateway) state(id string) *tenantState {
	g.mu.Lock()
	defer g.mu.Unlock()
	s, ok := g.tenants[id]
	if !ok {
		s = &tenantState{lastActivity: time.Now(), conns: map[net.Conn]struct{}{}}
		g.tenants[id] = s
	}
	return s
}

func (g *gateway) forget(id string) {
	s := g.state(id)
	s.mu.Lock()
	s.ip = ""
	s.mu.Unlock()
}

func (g *gateway) touch(id string) {
	s := g.state(id)
	s.mu.Lock()
	s.lastActivity = time.Now()
	s.mu.Unlock()
}

// ---- proxy ----

func (g *gateway) serveProxy() {
	certs, err := newCertReloader(g.cfg.certFile, g.cfg.keyFile)
	if err != nil {
		log.Fatal(err)
	}
	go g.servePostgres(certs)
	go g.serveMongo(certs)
	go g.serveMySQL(certs)
	ln, err := tls.Listen("tcp", g.cfg.proxyAddr, &tls.Config{GetCertificate: certs.get, MinVersion: tls.VersionTLS12})
	if err != nil {
		log.Fatal(err)
	}
	log.Printf("proxy on %s", g.cfg.proxyAddr)
	for {
		conn, err := ln.Accept()
		if err != nil {
			continue
		}
		go g.handle(conn.(*tls.Conn))
	}
}

func (g *gateway) tenantFromSNI(name string) (string, bool) {
	return tenantFromName(name, g.cfg.domain)
}

// tenantFromName is the tenant id in {id}.{domain}, or false.
func tenantFromName(name, domain string) (string, bool) {
	suffix := "." + domain
	if !strings.HasSuffix(name, suffix) {
		return "", false
	}
	id := strings.TrimSuffix(name, suffix)
	return id, validID(id)
}

func (g *gateway) handle(client *tls.Conn) {
	defer client.Close()
	_ = client.SetDeadline(time.Now().Add(15 * time.Second))
	if err := client.Handshake(); err != nil {
		return
	}
	id, ok := g.tenantFromSNI(client.ConnectionState().ServerName)
	if !ok {
		return
	}
	var upstream net.Conn
	for attempt := 0; attempt < 2 && upstream == nil; attempt++ {
		started := time.Now()
		ip, err := g.wake(context.Background(), id)
		if err != nil {
			log.Printf("tenant %s: wake failed: %v", id, err)
			_, _ = client.Write([]byte("-ERR this database could not start\r\n"))
			return
		}
		if waited := time.Since(started); waited > 100*time.Millisecond {
			log.Printf("tenant %s: woke in %s", id, waited.Round(time.Millisecond))
		}
		if upstream, err = net.DialTimeout("tcp", net.JoinHostPort(ip, "6379"), 5*time.Second); err != nil {
			upstream = nil
			g.forget(id) // the pod went away; wake again
		}
	}
	if upstream == nil {
		_, _ = client.Write([]byte("-ERR this database is not reachable\r\n"))
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

// copyTouching copies src to dst and calls touch on each read, at most once a second.
func copyTouching(dst net.Conn, src net.Conn, touch func()) {
	buf := make([]byte, 32*1024)
	var last time.Time
	for {
		n, err := src.Read(buf)
		if n > 0 {
			if touch != nil && time.Since(last) > time.Second {
				touch()
				last = time.Now()
			}
			if _, werr := dst.Write(buf[:n]); werr != nil {
				return
			}
		}
		if err != nil {
			if cw, ok := dst.(interface{ CloseWrite() error }); ok {
				_ = cw.CloseWrite()
			}
			return
		}
	}
}

// ---- sleep ----

func (g *gateway) reap(ctx context.Context) {
	for range time.Tick(10 * time.Second) {
		g.fillPool(ctx)
		tenants, err := g.listTenants(ctx)
		if err != nil {
			log.Printf("reap: %v", err)
			continue
		}
		for _, t := range tenants {
			if isDatabase(t.Engine) {
				g.reapDatabase(ctx, t)
				continue
			}
			pod, awake := g.tenantPod(ctx, t.ID)
			s := g.state(t.ID)
			s.mu.Lock()
			if s.ip != "" && (!awake || s.ip != pod.Status.PodIP) {
				s.ip = "" // the pod went away or moved; the next connection re-checks
			}
			s.mu.Unlock()
			if !awake {
				continue
			}
			ip := pod.Status.PodIP
			g.recoverRestart(ctx, t, pod)
			s.mu.Lock()
			idle := time.Since(s.lastActivity)
			sinceSnapshot := time.Since(s.lastSnapshot)
			s.mu.Unlock()
			if t.SleepAfter > 0 && idle > time.Duration(t.SleepAfter)*time.Second {
				if err := g.sleep(ctx, t, ip); err != nil {
					log.Printf("tenant %s: sleep failed: %v", t.ID, err)
				}
				continue
			}
			if !t.Persistent && sinceSnapshot > g.cfg.snapshotAge {
				if _, err := g.snapshot(ctx, t, ip); err != nil {
					log.Printf("tenant %s: snapshot failed: %v", t.ID, err)
				} else {
					s.mu.Lock()
					s.lastSnapshot = time.Now()
					s.mu.Unlock()
				}
			}
		}
	}
}

// reapDatabase parks an idle database. The pod stays; only the process stops.
func (g *gateway) reapDatabase(ctx context.Context, t tenant) {
	s := g.state(t.ID)
	// A wake holds the lock while its pod starts (up to minutes). Skip this
	// database until the next pass rather than stall the reaper for all.
	if !s.mu.TryLock() {
		return
	}
	awake := s.ip != ""
	idle := time.Since(s.lastActivity)
	s.mu.Unlock()
	if awake && t.SleepAfter > 0 && idle > time.Duration(t.SleepAfter)*time.Second {
		if err := g.sleepDatabase(ctx, t, true); errors.Is(err, errBusy) {
			// A backup or a long query with no traffic: it is not idle. Look
			// again after another full sleep-after.
			log.Printf("tenant %s: no traffic but %v; staying awake", t.ID, err)
			s.mu.Lock()
			s.lastActivity = time.Now()
			s.mu.Unlock()
		} else if err != nil {
			log.Printf("tenant %s: sleep failed: %v", t.ID, err)
		}
	}
}

// recoverRestart handles a container that restarted inside its pod: it comes
// back with the tenant login off and, for flex, empty. Turn the login back on
// and restore the last snapshot.
func (g *gateway) recoverRestart(ctx context.Context, t tenant, pod *corev1.Pod) {
	restarts := int32(0)
	if len(pod.Status.ContainerStatuses) > 0 {
		restarts = pod.Status.ContainerStatuses[0].RestartCount
	}
	s := g.state(t.ID)
	s.mu.Lock()
	defer s.mu.Unlock()
	seen, known := s.restarts[pod.Name]
	if s.restarts == nil {
		s.restarts = map[string]int32{}
	}
	s.restarts[pod.Name] = restarts
	if !known || seen == restarts {
		return
	}
	log.Printf("tenant %s: container restarted, restoring", t.ID)
	if err := waitReady(pod.Status.PodIP, g.cfg.adminPassword, 20*time.Second); err != nil {
		return
	}
	if err := g.configure(pod.Status.PodIP, t); err == nil && !t.Persistent {
		_ = g.restore(ctx, t, pod.Status.PodIP)
	}
}

// snapshot streams every key to the bucket. Returns the key count. The caller
// records lastSnapshot (sleep already holds the tenant lock).
func (g *gateway) snapshot(ctx context.Context, t tenant, ip string) (int, error) {
	c, err := dialAdmin(net.JoinHostPort(ip, "6379"), g.cfg.adminPassword)
	if err != nil {
		return 0, err
	}
	defer c.Close()
	pr, pw := io.Pipe()
	counted := make(chan int, 1)
	go func() {
		n, err := dumpKeys(c, pw)
		counted <- n
		_ = pw.CloseWithError(err)
	}()
	if err := g.store.put(ctx, t.ID, pr); err != nil {
		_ = pr.CloseWithError(err)
		return 0, err
	}
	return <-counted, nil
}

func (g *gateway) sleep(ctx context.Context, t tenant, ip string) error {
	// Held until the pod is gone, so no connection can wake it mid-snapshot.
	s := g.state(t.ID)
	s.mu.Lock()
	defer s.mu.Unlock()
	for c := range s.conns {
		_ = c.Close() // no writes after the snapshot starts
	}
	s.ip = ""
	n, err := g.snapshot(ctx, t, ip)
	if err != nil {
		return err
	}
	s.lastSnapshot = time.Now()
	log.Printf("tenant %s: asleep (%d key snapshot)", t.ID, n)
	if err := g.deletePod(ctx, t.ID); err != nil {
		return err
	}
	g.markAsleep(ctx, t.ID)
	return nil
}

// ---- control API (called by the dply app) ----

func (g *gateway) serveAPI() {
	mux := http.NewServeMux()
	mux.HandleFunc("PUT /tenants/{id}", g.auth(g.putTenant))
	mux.HandleFunc("GET /tenants/{id}", g.auth(g.getTenant))
	mux.HandleFunc("DELETE /tenants/{id}", g.auth(g.deleteTenant))
	mux.HandleFunc("POST /tenants/{id}/sleep", g.auth(g.sleepTenant))
	mux.HandleFunc("POST /tenants/{id}/restore", g.auth(g.restoreTenant))
	mux.HandleFunc("GET /tenants/{id}/backup", g.auth(g.backupStatus))
	mux.HandleFunc("GET /usage", g.authOnly(g.usage))
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, _ *http.Request) { _, _ = w.Write([]byte("ok")) })
	log.Printf("api on %s", g.cfg.apiAddr)
	// API_TLS=1 serves the control API over TLS with the proxy's cert, for
	// when it is reachable from outside the cluster (the bearer token must
	// not cross the internet in the clear).
	if os.Getenv("API_TLS") == "1" {
		certs, err := newCertReloader(g.cfg.certFile, g.cfg.keyFile)
		if err != nil {
			log.Fatal(err)
		}
		srv := &http.Server{Addr: g.cfg.apiAddr, Handler: mux, TLSConfig: &tls.Config{GetCertificate: certs.get, MinVersion: tls.VersionTLS12}}
		log.Fatal(srv.ListenAndServeTLS("", ""))
	}
	log.Fatal(http.ListenAndServe(g.cfg.apiAddr, mux))
}

func (g *gateway) authOnly(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		got := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
		if subtle.ConstantTimeCompare([]byte(got), []byte(g.cfg.apiToken)) != 1 {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		next(w, r)
	}
}

// usage is each tenant's total awake seconds, for per-second billing.
func (g *gateway) usage(w http.ResponseWriter, r *http.Request) {
	totals, err := g.awakeSeconds(r.Context())
	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"awake_seconds": totals})
}

func (g *gateway) auth(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		got := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
		if subtle.ConstantTimeCompare([]byte(got), []byte(g.cfg.apiToken)) != 1 {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		if !validID(r.PathValue("id")) {
			http.Error(w, "bad tenant id", http.StatusBadRequest)
			return
		}
		next(w, r)
	}
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

func (g *gateway) putTenant(w http.ResponseWriter, r *http.Request) {
	var t tenant
	if err := json.NewDecoder(r.Body).Decode(&t); err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}
	t.ID = r.PathValue("id")
	t.Engine = engineOrValkey(t.Engine)
	if t.Engine != "valkey" && !isDatabase(t.Engine) {
		http.Error(w, "engine must be valkey, postgres, mongodb or mysql", http.StatusUnprocessableEntity)
		return
	}
	if isDatabase(t.Engine) {
		t.Persistent = true // data lives on the volume
		t.DiskGB = max(t.DiskGB, 1)
	}
	if t.MemoryMB < 25 || t.MemoryMB > 64*1024 || len(t.Password) < 16 {
		http.Error(w, "memory_mb must be 25-65536 and password at least 16 characters", http.StatusUnprocessableEntity)
		return
	}
	previous, _ := g.getTenantRecord(r.Context(), t.ID)
	// Refuse before saving: a refused change must not overwrite the record.
	if previous != nil && previous.Engine != t.Engine {
		http.Error(w, "the engine cannot change; delete and create", http.StatusUnprocessableEntity)
		return
	}
	if previous != nil && isDatabase(t.Engine) && t.DiskGB < previous.DiskGB {
		http.Error(w, "disk_gb cannot shrink; a volume only grows", http.StatusUnprocessableEntity)
		return
	}
	if err := g.saveTenant(r.Context(), t); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}

	// A database applies a new size or password on its next wake. A bigger
	// disk grows the volume now (online; the filesystem follows).
	if previous != nil && isDatabase(t.Engine) {
		if t.DiskGB > previous.DiskGB {
			if err := g.growDatabaseVolume(r.Context(), t.ID, t.DiskGB); err != nil {
				http.Error(w, err.Error(), http.StatusInternalServerError)
				return
			}
		}
		if previous.MemoryMB != t.MemoryMB || previous.Password != t.Password {
			if err := g.sleepDatabaseSoon(r.Context(), *previous); err != nil {
				http.Error(w, err.Error(), http.StatusInternalServerError)
				return
			}
		}
		writeJSON(w, http.StatusOK, g.status(r.Context(), t))
		return
	}
	// A new size or password applies on the next wake: snapshot, then restart.
	if previous != nil && (previous.MemoryMB != t.MemoryMB || previous.Password != t.Password || previous.Persistent != t.Persistent) {
		if ip, awake := g.podIP(r.Context(), t.ID); awake {
			if err := g.sleep(r.Context(), *previous, ip); err != nil {
				http.Error(w, err.Error(), http.StatusInternalServerError)
				return
			}
		}
	}
	writeJSON(w, http.StatusOK, g.status(r.Context(), t))
	// A new database is built now, not on the app's first connection: the
	// volume, image pull and initdb can take minutes, longer than a client
	// waits. Started after the reply, since the build holds the tenant's
	// lock and status() needs it. It parks after sleep_after like any wake.
	if previous == nil && isDatabase(t.Engine) {
		go func(id string) {
			ctx, cancel := context.WithTimeout(context.Background(), 15*time.Minute)
			defer cancel()
			if _, err := g.wakeDatabase(ctx, id); err != nil {
				log.Printf("tenant %s: first start failed (retried on connect): %v", id, err)
			}
		}(t.ID)
	}
}

// restoreTenant: point-in-time restore of a database from its wal-g backups.
// Body {"target_time": "RFC3339"}; empty restores to the latest point.
func (g *gateway) restoreTenant(w http.ResponseWriter, r *http.Request) {
	t, err := g.getTenantRecord(r.Context(), r.PathValue("id"))
	if err != nil {
		http.Error(w, "not found", http.StatusNotFound)
		return
	}
	if !isDatabase(t.Engine) {
		http.Error(w, "only databases can be restored", http.StatusUnprocessableEntity)
		return
	}
	var body struct {
		TargetTime string `json:"target_time"`
	}
	_ = json.NewDecoder(r.Body).Decode(&body)
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Minute)
	defer cancel()
	if err := g.restoreDatabase(ctx, *t, body.TargetTime); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	writeJSON(w, http.StatusOK, g.status(r.Context(), *t))
}

// backupStatus relays the database agent's last backup result. The pod
// stays up while the database sleeps, so this answers either way.
func (g *gateway) backupStatus(w http.ResponseWriter, r *http.Request) {
	pod, ok := g.databasePod(r.Context(), r.PathValue("id"))
	if !ok || pod.Status.PodIP == "" {
		writeJSON(w, http.StatusOK, map[string]any{})
		return
	}
	req, _ := http.NewRequestWithContext(r.Context(), http.MethodGet, "http://"+net.JoinHostPort(pod.Status.PodIP, agentPort)+"/backup-status", nil)
	req.Header.Set("Authorization", "Bearer "+g.cfg.adminPassword)
	resp, err := (&http.Client{Timeout: 10 * time.Second}).Do(req)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadGateway)
		return
	}
	defer resp.Body.Close()
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(resp.StatusCode)
	_, _ = io.Copy(w, resp.Body)
}

func (g *gateway) getTenant(w http.ResponseWriter, r *http.Request) {
	t, err := g.getTenantRecord(r.Context(), r.PathValue("id"))
	if err != nil {
		http.Error(w, "not found", http.StatusNotFound)
		return
	}
	writeJSON(w, http.StatusOK, g.status(r.Context(), *t))
}

func (g *gateway) sleepTenant(w http.ResponseWriter, r *http.Request) {
	t, err := g.getTenantRecord(r.Context(), r.PathValue("id"))
	if err != nil {
		http.Error(w, "not found", http.StatusNotFound)
		return
	}
	if isDatabase(t.Engine) {
		if err := g.sleepDatabaseSoon(r.Context(), *t); err != nil {
			http.Error(w, err.Error(), http.StatusInternalServerError)
			return
		}
	} else if ip, awake := g.podIP(r.Context(), t.ID); awake {
		if err := g.sleep(r.Context(), *t, ip); err != nil {
			http.Error(w, err.Error(), http.StatusInternalServerError)
			return
		}
	}
	writeJSON(w, http.StatusOK, g.status(r.Context(), *t))
}

func (g *gateway) deleteTenant(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	g.forget(id)
	g.deleteDatabase(r.Context(), id)
	_ = g.deletePod(r.Context(), id)
	_ = g.deletePVC(r.Context(), id)
	_ = g.store.remove(r.Context(), id)
	if err := g.deleteTenantRecord(r.Context(), id); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	w.WriteHeader(http.StatusNoContent)
}

type tenantStatus struct {
	ID           string `json:"id"`
	Host         string `json:"host"`
	Awake        bool   `json:"awake"`
	MemoryMB     int    `json:"memory_mb"`
	SleepAfter   int    `json:"sleep_after"`
	Persistent   bool   `json:"persistent"`
	IdleSeconds  int    `json:"idle_seconds"`
	HasSnapshot  bool   `json:"has_snapshot"`
	UsedMemoryMB int    `json:"used_memory_mb,omitempty"`
}

func (g *gateway) status(ctx context.Context, t tenant) tenantStatus {
	_, awake := g.podIP(ctx, t.ID)
	s := g.state(t.ID)
	s.mu.Lock()
	idle := int(time.Since(s.lastActivity).Seconds())
	if isDatabase(t.Engine) {
		awake = s.ip != ""
	}
	s.mu.Unlock()
	return tenantStatus{
		ID: t.ID, Host: t.ID + "." + g.hostDomain(t), Awake: awake, MemoryMB: t.MemoryMB,
		SleepAfter: t.SleepAfter, Persistent: t.Persistent, IdleSeconds: idle,
		HasSnapshot: g.store.exists(ctx, t.ID),
	}
}

// hostDomain: databases answer on DB_DOMAIN, Valkey on DOMAIN.
func (g *gateway) hostDomain(t tenant) string {
	if isDatabase(t.Engine) {
		return g.cfg.dbDomain
	}
	return g.cfg.domain
}

func validID(id string) bool {
	if len(id) < 3 || len(id) > 40 {
		return false
	}
	for _, c := range id {
		if !(c >= 'a' && c <= 'z' || c >= '0' && c <= '9' || c == '-') {
			return false
		}
	}
	return !strings.HasPrefix(id, "-") && !strings.HasSuffix(id, "-")
}

var errNotFound = errors.New("not found")

// parsePool reads "250:2,1024:1": keep two warm 250 MB pods and one 1 GB pod.
func parsePool(spec string) map[int]int {
	out := map[int]int{}
	for _, part := range strings.Split(spec, ",") {
		mb, n, ok := strings.Cut(strings.TrimSpace(part), ":")
		if ok && atoi(mb) > 0 && atoi(n) > 0 {
			out[atoi(mb)] = atoi(n)
		}
	}
	return out
}

func atoi(s string) int {
	n, _ := strconv.Atoi(s)
	return n
}
