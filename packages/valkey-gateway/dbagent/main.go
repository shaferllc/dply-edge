// dbagent is PID 1 in a dply database pod (ruling r-r5h70qp951w28qrh). The
// pod stays up while the database sleeps; the gateway calls this agent to
// start and stop the database process, so a wake is a process start, not a
// pod start. The agent uses the engine's own local tools, so the gateway
// needs no database client libraries.
//
// HTTP on :7000, bearer AGENT_TOKEN:
//
//	POST /start   create the data directory on first use, start, wait until ready
//	POST /stop    clean stop (checkpoint), so the next start has no recovery
//	POST /tenant  {"password": "..."} create or update the app's login and database
//	GET  /healthz
package main

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"time"
)

type engine interface {
	initialized() bool
	init() error
	start() error
	stop() error
	setTenant(password string) error
}

func main() {
	token := strings.TrimSpace(os.Getenv("AGENT_TOKEN"))
	if len(token) < 16 {
		log.Fatal("AGENT_TOKEN (16+ characters) is required")
	}
	var e engine
	switch os.Getenv("ENGINE") {
	case "postgres":
		e = &postgres{data: "/data/pg", run: "/data/run", admin: strings.TrimSpace(os.Getenv("AGENT_TOKEN"))}
	default:
		log.Fatalf("unknown ENGINE %q", os.Getenv("ENGINE"))
	}

	var mu sync.Mutex // one lifecycle call at a time
	mux := http.NewServeMux()
	handle := func(path string, fn func(r *http.Request) error) {
		mux.HandleFunc(path, func(w http.ResponseWriter, r *http.Request) {
			got := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
			if subtle.ConstantTimeCompare([]byte(got), []byte(token)) != 1 {
				http.Error(w, "unauthorized", http.StatusUnauthorized)
				return
			}
			mu.Lock()
			defer mu.Unlock()
			started := time.Now()
			if err := fn(r); err != nil {
				log.Printf("%s: %v", path, err)
				http.Error(w, err.Error(), http.StatusInternalServerError)
				return
			}
			log.Printf("%s in %s", path, time.Since(started).Round(time.Millisecond))
			w.WriteHeader(http.StatusNoContent)
		})
	}
	handle("POST /start", func(*http.Request) error {
		if !e.initialized() {
			if err := e.init(); err != nil {
				return err
			}
		}
		return e.start()
	})
	handle("POST /stop", func(*http.Request) error { return e.stop() })
	handle("POST /tenant", func(r *http.Request) error {
		var body struct{ Password string }
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil || len(body.Password) < 16 {
			return fmt.Errorf("password of 16+ characters required")
		}
		return e.setTenant(body.Password)
	})
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, _ *http.Request) { _, _ = w.Write([]byte("ok")) })

	srv := &http.Server{Addr: ":7000", Handler: mux}
	go func() {
		stop := make(chan os.Signal, 1)
		signal.Notify(stop, syscall.SIGTERM, syscall.SIGINT)
		<-stop
		mu.Lock()
		_ = e.stop() // a pod shutdown still stops the database cleanly
		mu.Unlock()
		_ = srv.Shutdown(context.Background())
	}()
	log.Printf("dbagent (%s) on :7000", os.Getenv("ENGINE"))
	if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Fatal(err)
	}
}

func run(name string, args ...string) error {
	out, err := exec.Command(name, args...).CombinedOutput()
	if err != nil {
		return fmt.Errorf("%s: %v: %s", name, err, strings.TrimSpace(string(out)))
	}
	return nil
}

// ---- Postgres ----

type postgres struct{ data, run, admin string }

func (p *postgres) initialized() bool {
	_, err := os.Stat(filepath.Join(p.data, "PG_VERSION"))
	return err == nil
}

func (p *postgres) init() error {
	if err := os.MkdirAll(p.run, 0o700); err != nil {
		return err
	}
	pw := filepath.Join(p.run, ".pw")
	if err := os.WriteFile(pw, []byte(p.admin), 0o600); err != nil {
		return err
	}
	defer os.Remove(pw)
	// The admin role only logs in over the pod's own socket; the app role
	// logs in over the network with a password.
	if err := run("initdb", "-D", p.data, "-U", "dply_admin", "--pwfile", pw, "--auth-local=peer", "--auth-host=scram-sha-256"); err != nil {
		return err
	}
	hba := "local all all trust\nhost all dply_admin all reject\nhost all all all scram-sha-256\n"
	if err := os.WriteFile(filepath.Join(p.data, "pg_hba.conf"), []byte(hba), 0o600); err != nil {
		return err
	}
	conf := fmt.Sprintf("listen_addresses = '*'\nunix_socket_directories = '%s'\nshared_preload_libraries = 'pg_prewarm'\nmax_connections = %s\n", p.run, envOr("MAX_CONNECTIONS", "50"))
	f, err := os.OpenFile(filepath.Join(p.data, "postgresql.auto.conf"), os.O_APPEND|os.O_WRONLY, 0o600)
	if err != nil {
		return err
	}
	defer f.Close()
	_, err = f.WriteString(conf)
	return err
}

func (p *postgres) start() error {
	if err := os.MkdirAll(p.run, 0o700); err != nil {
		return err
	}
	if run("pg_ctl", "-D", p.data, "status") == nil {
		return nil
	}
	return run("pg_ctl", "-D", p.data, "-w", "-t", "60", "-l", filepath.Join(p.run, "postgres.log"), "start")
}

func (p *postgres) stop() error {
	if run("pg_ctl", "-D", p.data, "status") != nil {
		return nil
	}
	// "fast" rolls back open transactions and checkpoints, so the next start is quick.
	return run("pg_ctl", "-D", p.data, "-w", "-t", "60", "-m", "fast", "stop")
}

func (p *postgres) setTenant(password string) error {
	quoted := "'" + strings.ReplaceAll(password, "'", "''") + "'"
	psql := func(db, sql string) error {
		return run("psql", "-h", p.run, "-U", "dply_admin", "-d", db, "-v", "ON_ERROR_STOP=1", "-qAtc", sql)
	}
	if err := psql("postgres", `DO $$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'app') THEN CREATE ROLE app LOGIN; END IF;
END $$; ALTER ROLE app WITH LOGIN NOSUPERUSER NOCREATEROLE NOREPLICATION PASSWORD `+quoted); err != nil {
		return err
	}
	out, err := exec.Command("psql", "-h", p.run, "-U", "dply_admin", "-d", "postgres", "-qAtc", "SELECT 1 FROM pg_database WHERE datname = 'app'").Output()
	if err != nil {
		return err
	}
	if strings.TrimSpace(string(out)) != "1" {
		if err := psql("postgres", "CREATE DATABASE app OWNER app"); err != nil {
			return err
		}
	}
	return nil
}

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
