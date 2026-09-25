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
//	POST /restore {"target_time": "RFC3339"} point-in-time restore from wal-g (empty: latest)
//	GET  /healthz
//
// Backups (Postgres): with WALG_S3_PREFIX set, finished WAL segments stream
// to object storage (archive_timeout 60 s) and a base backup is taken on the
// first start and then daily while the database is up, keeping 7.
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
	"strconv"
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
	restore(target string) error
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
	case "mongodb":
		e = &mongo{data: "/data/mongo", run: "/data/run", admin: strings.TrimSpace(os.Getenv("AGENT_TOKEN"))}
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
	handle("POST /restore", func(r *http.Request) error {
		var body struct {
			TargetTime string `json:"target_time"`
		}
		_ = json.NewDecoder(r.Body).Decode(&body)
		if body.TargetTime != "" {
			if _, err := time.Parse(time.RFC3339, body.TargetTime); err != nil {
				return fmt.Errorf("target_time must be RFC3339: %v", err)
			}
		}
		return e.restore(body.TargetTime)
	})
	if pg, ok := e.(*postgres); ok && backupsEnabled() {
		go pg.backupLoop()
	}
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
	// builtin C.UTF-8: sorting and case rules come from Postgres itself, not
	// the OS C library, so a new base image never reorders text indexes.
	if err := run("initdb", "-D", p.data, "-U", "dply_admin", "--pwfile", pw, "--auth-local=peer", "--auth-host=scram-sha-256",
		"--encoding=UTF8", "--locale=C.UTF-8", "--locale-provider=builtin", "--builtin-locale=C.UTF-8"); err != nil {
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
	// Postgres refuses a data directory looser than 0750. A volume that was
	// attached with a group ownership change (fsGroup) can come back 2770.
	if _, err := os.Stat(p.data); err == nil {
		if err := os.Chmod(p.data, 0o700); err != nil {
			return err
		}
	}
	return run("pg_ctl", "-D", p.data, "-w", "-t", "60", "-l", filepath.Join(p.run, "postgres.log"), "-o", archiveOptions(), "start")
}

// ---- backups (wal-g) ----

func backupsEnabled() bool { return os.Getenv("WALG_S3_PREFIX") != "" }

// archiveOptions are passed at start (pg_ctl -o goes through sh), so a
// database created before backups existed picks them up on its next wake.
func archiveOptions() string {
	if !backupsEnabled() {
		return ""
	}
	return `-c archive_mode=on -c archive_timeout=60 -c 'archive_command=wal-g wal-push %p'`
}

// walg runs wal-g against the local server over the pod's socket.
func (p *postgres) walg(args ...string) ([]byte, error) {
	cmd := exec.Command("wal-g", args...)
	cmd.Env = append(os.Environ(), "PGHOST="+p.run, "PGUSER=dply_admin", "PGDATABASE=postgres", "PGDATA="+p.data)
	// stdout is the result (backup-list JSON); wal-g logs to stderr.
	var stderr strings.Builder
	cmd.Stderr = &stderr
	out, err := cmd.Output()
	if err != nil {
		return out, fmt.Errorf("wal-g %s: %v: %s", strings.Join(args, " "), err, strings.TrimSpace(lastLines(stderr.String(), 4)))
	}
	return out, nil
}

func (p *postgres) backupMarker() string { return filepath.Join(filepath.Dir(p.data), "backup-at") }

// backupLoop takes a base backup when the database is up and the last one is
// more than a day old (or missing). It does not hold the lifecycle lock: a
// sleep that stops Postgres mid-backup just fails this one, and the next tick
// retries.
func (p *postgres) backupLoop() {
	for {
		if run("pg_ctl", "-D", p.data, "status") == nil && p.backupDue() {
			started := time.Now()
			if _, err := p.walg("backup-push", p.data); err != nil {
				log.Printf("backup: %v", err)
			} else {
				_ = os.WriteFile(p.backupMarker(), []byte(time.Now().UTC().Format(time.RFC3339)), 0o600)
				log.Printf("backup: done in %s", time.Since(started).Round(time.Second))
				if _, err := p.walg("delete", "retain", "FULL", "7", "--confirm"); err != nil {
					log.Printf("backup retention: %v", err)
				}
			}
		}
		time.Sleep(time.Minute)
	}
}

func (p *postgres) backupDue() bool {
	b, err := os.ReadFile(p.backupMarker())
	if err != nil {
		return true
	}
	at, err := time.Parse(time.RFC3339, strings.TrimSpace(string(b)))
	return err != nil || time.Since(at) > 24*time.Hour
}

// restore replaces the data directory with a base backup plus WAL replayed
// to target (RFC3339, or the end of the archive when empty). The current data
// is kept as <data>.pre-restore until the next restore, and put back if the
// restore fails.
func (p *postgres) restore(target string) error {
	if !backupsEnabled() {
		return fmt.Errorf("backups are not configured")
	}
	// WAL reaches storage a whole segment at a time. Close the current one and
	// wait for it, so a restore to "a minute ago" has that minute's changes.
	if err := p.flushWAL(90 * time.Second); err != nil {
		log.Printf("restore: %v (restoring from what is archived)", err)
	}
	if err := p.stop(); err != nil {
		return err
	}
	name := "LATEST"
	if target != "" {
		chosen, err := p.backupBefore(target)
		if err != nil {
			return err
		}
		name = chosen
	}
	aside := p.data + ".pre-restore"
	_ = os.RemoveAll(aside)
	if err := os.Rename(p.data, aside); err != nil {
		return err
	}
	undo := func(cause error) error {
		_ = os.RemoveAll(p.data)
		if err := os.Rename(aside, p.data); err != nil {
			return fmt.Errorf("%v (and putting the old data back failed: %v)", cause, err)
		}
		_ = p.start()
		return cause
	}
	if _, err := p.walg("backup-fetch", p.data, name); err != nil {
		return undo(err)
	}
	if err := os.Chmod(p.data, 0o700); err != nil {
		return undo(err)
	}
	if err := os.WriteFile(filepath.Join(p.data, "recovery.signal"), nil, 0o600); err != nil {
		return undo(err)
	}
	opts := archiveOptions() + ` -c 'restore_command=wal-g wal-fetch %f %p' -c recovery_target_action=promote`
	if target != "" {
		// Postgres wants its own timestamp form, not RFC3339's "T…Z".
		at, _ := time.Parse(time.RFC3339, target)
		opts += ` -c 'recovery_target_time=` + at.UTC().Format("2006-01-02 15:04:05.000000+00") + `'`
	}
	logFile := filepath.Join(p.run, "postgres.log")
	if err := run("pg_ctl", "-D", p.data, "-w", "-t", "900", "-l", logFile, "-o", opts, "start"); err != nil {
		tail, _ := os.ReadFile(logFile)
		return undo(fmt.Errorf("%v; postgres: %s", err, lastLines(string(tail), 3)))
	}
	// pg_ctl returns once connections are accepted, which during recovery is
	// before the replay reaches the target and promotes. Wait for read-write.
	deadline := time.Now().Add(15 * time.Minute)
	for {
		out, err := exec.Command("psql", "-h", p.run, "-U", "dply_admin", "-d", "postgres", "-qAtc", "SELECT pg_is_in_recovery()").Output()
		if err == nil && strings.TrimSpace(string(out)) == "f" {
			break
		}
		if time.Now().After(deadline) {
			tail, _ := os.ReadFile(logFile)
			return fmt.Errorf("restore did not finish recovery in 15 minutes; postgres: %s", lastLines(string(tail), 3))
		}
		time.Sleep(time.Second)
	}
	// The restored timeline needs a base backup of its own.
	_ = os.Remove(p.backupMarker())
	return nil
}

// flushWAL switches to a new WAL segment and waits until the one it closed
// has been archived. No-op when the database is not running.
func (p *postgres) flushWAL(wait time.Duration) error {
	if run("pg_ctl", "-D", p.data, "status") != nil {
		return nil
	}
	query := func(sql string) (string, error) {
		out, err := exec.Command("psql", "-h", p.run, "-U", "dply_admin", "-d", "postgres", "-qAtc", sql).Output()
		return strings.TrimSpace(string(out)), err
	}
	closed, err := query("SELECT pg_walfile_name(pg_switch_wal())")
	if err != nil {
		return fmt.Errorf("switching WAL: %v", err)
	}
	deadline := time.Now().Add(wait)
	for time.Now().Before(deadline) {
		// WAL file names sort in order; the archiver has caught up once its
		// last archived segment is the one we closed (or later).
		last, err := query("SELECT coalesce(last_archived_wal, '') FROM pg_stat_archiver")
		if err == nil && len(last) >= 24 && last[:24] >= closed {
			return nil
		}
		time.Sleep(time.Second)
	}
	return fmt.Errorf("WAL segment %s not archived within %s", closed, wait)
}

// backupBefore is the newest base backup that finished before target.
func (p *postgres) backupBefore(target string) (string, error) {
	want, _ := time.Parse(time.RFC3339, target)
	out, err := p.walg("backup-list", "--json", "--detail")
	if err != nil {
		return "", err
	}
	var backups []struct {
		Name       string    `json:"backup_name"`
		FinishTime time.Time `json:"finish_time"`
	}
	if err := json.Unmarshal(out, &backups); err != nil {
		return "", fmt.Errorf("backup-list: %v", err)
	}
	best := ""
	var bestAt time.Time
	for _, b := range backups {
		if !b.FinishTime.After(want) && b.FinishTime.After(bestAt) {
			best, bestAt = b.Name, b.FinishTime
		}
	}
	if best == "" {
		return "", fmt.Errorf("no backup finished before %s", target)
	}
	return best, nil
}

func lastLines(s string, n int) string {
	lines := strings.Split(strings.TrimSpace(s), "\n")
	if len(lines) > n {
		lines = lines[len(lines)-n:]
	}
	return strings.Join(lines, "\n")
}

func (p *postgres) stop() error {
	if run("pg_ctl", "-D", p.data, "status") != nil {
		return nil
	}
	// The postmaster detached from pg_ctl and was reparented to dbagent (PID
	// 1): reap it after it exits, or every sleep leaves a zombie behind.
	pid := 0
	if b, err := os.ReadFile(filepath.Join(p.data, "postmaster.pid")); err == nil {
		pid, _ = strconv.Atoi(strings.TrimSpace(strings.SplitN(string(b), "\n", 2)[0]))
	}
	// "fast" rolls back open transactions and checkpoints, so the next start is quick.
	if err := run("pg_ctl", "-D", p.data, "-w", "-t", "60", "-m", "fast", "stop"); err != nil {
		return err
	}
	if pid > 0 {
		var status syscall.WaitStatus
		_, _ = syscall.Wait4(pid, &status, syscall.WNOHANG, nil)
	}
	return nil
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
