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
	"log"
	"net"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"

	"k8s.io/client-go/kubernetes"
	"k8s.io/client-go/rest"
)

type config struct {
	namespace   string
	domain      string
	image       string
	proxyAddr   string
	apiAddr     string
	apiToken    string
	certFile    string
	keyFile     string
	snapshotAge time.Duration
}

func env(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func main() {
	cfg := config{
		namespace:   env("NAMESPACE", "dply-valkey"),
		domain:      env("DOMAIN", "cache.dply.local"),
		image:       env("VALKEY_IMAGE", "valkey/valkey:8-alpine"),
		proxyAddr:   env("PROXY_ADDR", ":6380"),
		apiAddr:     env("API_ADDR", ":8080"),
		apiToken:    strings.TrimSpace(os.Getenv("API_TOKEN")),
		certFile:    env("TLS_CERT", "/tls/tls.crt"),
		keyFile:     env("TLS_KEY", "/tls/tls.key"),
		snapshotAge: 15 * time.Minute,
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
	ip           string // set once the pod answered PING; cleared on sleep

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
	cert, err := tls.LoadX509KeyPair(g.cfg.certFile, g.cfg.keyFile)
	if err != nil {
		log.Fatal(err)
	}
	ln, err := tls.Listen("tcp", g.cfg.proxyAddr, &tls.Config{Certificates: []tls.Certificate{cert}, MinVersion: tls.VersionTLS12})
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
	suffix := "." + g.cfg.domain
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
		tenants, err := g.listTenants(ctx)
		if err != nil {
			log.Printf("reap: %v", err)
			continue
		}
		for _, t := range tenants {
			ip, awake := g.podIP(ctx, t.ID)
			s := g.state(t.ID)
			s.mu.Lock()
			if s.ip != "" && s.ip != ip {
				s.ip = "" // the pod went away or moved; the next connection re-checks
			}
			s.mu.Unlock()
			if !awake {
				continue
			}
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
				if err := g.snapshot(ctx, t, ip); err != nil {
					log.Printf("tenant %s: snapshot failed: %v", t.ID, err)
				}
			}
		}
	}
}

func (g *gateway) snapshot(ctx context.Context, t tenant, ip string) error {
	rdb, err := pullRDB(net.JoinHostPort(ip, "6379"), t.Password)
	if err != nil {
		return err
	}
	if err := g.store.put(ctx, t.ID, rdb); err != nil {
		return err
	}
	s := g.state(t.ID)
	s.mu.Lock()
	s.lastSnapshot = time.Now()
	s.mu.Unlock()
	return nil
}

func (g *gateway) sleep(ctx context.Context, t tenant, ip string) error {
	s := g.state(t.ID)
	s.mu.Lock()
	defer s.mu.Unlock()
	rdb, err := pullRDB(net.JoinHostPort(ip, "6379"), t.Password)
	if err != nil {
		return err
	}
	if err := g.store.put(ctx, t.ID, rdb); err != nil {
		return err
	}
	for c := range s.conns {
		_ = c.Close()
	}
	s.lastSnapshot = time.Now()
	s.ip = ""
	log.Printf("tenant %s: asleep (%d byte snapshot)", t.ID, len(rdb))
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
	mux.HandleFunc("GET /usage", g.authOnly(g.usage))
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, _ *http.Request) { _, _ = w.Write([]byte("ok")) })
	log.Printf("api on %s", g.cfg.apiAddr)
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
	if t.MemoryMB < 25 || t.MemoryMB > 64*1024 || len(t.Password) < 16 {
		http.Error(w, "memory_mb must be 25-65536 and password at least 16 characters", http.StatusUnprocessableEntity)
		return
	}
	previous, _ := g.getTenantRecord(r.Context(), t.ID)
	if err := g.saveTenant(r.Context(), t); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
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
	if ip, awake := g.podIP(r.Context(), t.ID); awake {
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
	s.mu.Unlock()
	return tenantStatus{
		ID: t.ID, Host: t.ID + "." + g.cfg.domain, Awake: awake, MemoryMB: t.MemoryMB,
		SleepAfter: t.SleepAfter, Persistent: t.Persistent, IdleSeconds: idle,
		HasSnapshot: g.store.exists(ctx, t.ID),
	}
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

func atoi(s string) int {
	n, _ := strconv.Atoi(s)
	return n
}
