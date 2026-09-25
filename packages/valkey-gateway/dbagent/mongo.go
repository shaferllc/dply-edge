package main

import (
	"compress/gzip"
	"context"
	"crypto/rand"
	"encoding/base64"
	"fmt"
	"io"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/minio/minio-go/v7"
)

// mongo runs mongod as a forked child of dbagent. The admin user
// (dply_admin, password AGENT_TOKEN) is created once with auth off and bound
// to localhost; every later start has auth (the key file) on all interfaces. The app logs
// in as "app" on database "app".
//
// It runs as a one-member replica set so it has an oplog: backups are daily
// dumps plus the oplog shipped each minute (dump.go), so a restore can go to
// any second. The member is named by the database's public host
// (DB_PUBLIC_HOST, {id}.db.dply.io, mapped to 127.0.0.1 inside the pod), so
// a driver that discovers the set from its member list connects back through
// the gateway like the app did. A replica set with auth needs a key file.
type mongo struct {
	data, run, admin string
	backups          *dumps
	dumpPos          string // newest oplog entry when the last dump began
}

const replSet = "rs0"

func (m *mongo) keyFile() string     { return filepath.Join(filepath.Dir(m.data), "mongo.key") }
func (m *mongo) shippedFile() string { return filepath.Join(filepath.Dir(m.data), "oplog-shipped") }

// member is the replica set member's host:port.
func member() string { return envOr("DB_PUBLIC_HOST", "localhost") + ":27017" }

func (m *mongo) marker() string  { return filepath.Join(m.data, ".dply-initialized") }
func (m *mongo) pidFile() string { return filepath.Join(m.run, "mongod.pid") }
func (m *mongo) logFile() string { return filepath.Join(m.run, "mongod.log") }

func (m *mongo) initialized() bool {
	_, err := os.Stat(m.marker())
	return err == nil
}

// cacheGB is a quarter of the pod's memory limit (cgroup v2), at least 0.25:
// WiredTiger's default would size itself from the node, not the pod.
func cacheGB() string {
	gb := 0.25
	if b, err := os.ReadFile("/sys/fs/cgroup/memory.max"); err == nil {
		if n, err := strconv.ParseFloat(strings.TrimSpace(string(b)), 64); err == nil {
			gb = max(gb, n/4/(1<<30))
		}
	}
	return strconv.FormatFloat(gb, 'f', 2, 64)
}

func (m *mongo) mongod(extra ...string) error {
	// oplogSize is in MB: the default is 5% of the disk (at least 990 MB),
	// too big for a small volume. Shipping each minute keeps well inside it.
	args := append([]string{"--dbpath", m.data, "--port", "27017", "--fork", "--logpath", m.logFile(), "--logappend",
		"--pidfilepath", m.pidFile(), "--wiredTigerCacheSizeGB", cacheGB(), "--replSet", replSet, "--oplogSize", "64"}, extra...)
	return run("mongod", args...)
}

func (m *mongo) init() error {
	for _, dir := range []string{m.data, m.run} {
		if err := os.MkdirAll(dir, 0o700); err != nil {
			return err
		}
	}
	key := make([]byte, 96)
	if _, err := rand.Read(key); err != nil {
		return err
	}
	if err := os.WriteFile(m.keyFile(), []byte(base64.StdEncoding.EncodeToString(key)), 0o400); err != nil && !os.IsPermission(err) {
		return err
	}
	if err := m.mongod("--bind_ip", "127.0.0.1"); err != nil {
		return err
	}
	// Auth is off until the admin user exists (localhost only).
	// replSetGetStatus throws (NotYetInitialized) until the set is initiated.
	setup := fmt.Sprintf(`try { rs.status(); } catch (e) { rs.initiate({_id: %q, members: [{_id: 0, host: %q}]}); }
for (let i = 0; i < 120 && !db.hello().isWritablePrimary; i++) { sleep(500); }
if (!db.getSiblingDB("admin").getUser("dply_admin")) { db.getSiblingDB("admin").createUser({user: "dply_admin", pwd: %q, roles: ["root"]}); }`, replSet, member(), m.admin)
	if out, err := exec.Command("mongosh", "--quiet", "--port", "27017", "--eval", setup).CombinedOutput(); err != nil {
		_ = m.stop()
		return fmt.Errorf("mongosh: %v: %s", err, lastLines(string(out), 3))
	}
	if err := m.stop(); err != nil {
		return err
	}
	return os.WriteFile(m.marker(), []byte(time.Now().UTC().Format(time.RFC3339)), 0o600)
}

func (m *mongo) running() bool { return pidRunning(m.pidFile()) }

// pidRunning: a server started with --fork/--daemonize detaches, so it is
// reparented to dbagent (PID 1). After it exits it stays a zombie until
// reaped, and a zombie still answers kill(pid, 0). Read its state instead,
// and reap a zombie here, by pid, so Go's own exec waits are never disturbed.
func pidRunning(pidFile string) bool {
	b, err := os.ReadFile(pidFile)
	if err != nil {
		return false
	}
	pid, err := strconv.Atoi(strings.TrimSpace(string(b)))
	if err != nil || syscall.Kill(pid, 0) != nil {
		return false
	}
	if stat, err := os.ReadFile(fmt.Sprintf("/proc/%d/stat", pid)); err == nil {
		// Field 3, after "(comm)", is the state; Z is a zombie.
		if i := strings.LastIndexByte(string(stat), ')'); i >= 0 && i+2 < len(stat) && stat[i+2] == 'Z' {
			var status syscall.WaitStatus
			_, _ = syscall.Wait4(pid, &status, syscall.WNOHANG, nil)
			_ = os.Remove(pidFile)
			return false
		}
	}
	return true
}

func (m *mongo) start() error {
	if err := os.MkdirAll(m.run, 0o700); err != nil {
		return err
	}
	if m.running() {
		return nil
	}
	_ = os.Remove(filepath.Join(m.data, "mongod.lock")) // left by an unclean stop; the pod is the only writer
	if err := m.mongod("--bind_ip_all", "--keyFile", m.keyFile()); err != nil {
		tail, _ := os.ReadFile(m.logFile())
		return fmt.Errorf("%v; mongod: %s", err, lastLines(string(tail), 3))
	}
	// A replica set member takes a moment to elect itself; writes before that fail.
	wait := `for (let i = 0; i < 120 && !db.hello().isWritablePrimary; i++) { sleep(500); } if (!db.hello().isWritablePrimary) { quit(1); }`
	if out, err := exec.Command("mongosh", m.adminArgs("--quiet", "--eval", wait)...).CombinedOutput(); err != nil {
		return fmt.Errorf("not primary after 60s: %s", lastLines(string(out), 3))
	}
	return nil
}

func (m *mongo) stop() error {
	if !m.running() {
		return nil
	}
	// --shutdown flushes and exits cleanly (the same as db.shutdownServer()).
	return run("mongod", "--dbpath", m.data, "--shutdown")
}

func (m *mongo) setTenant(password string) error {
	script := fmt.Sprintf(`const app = db.getSiblingDB("app");
const roles = [{role: "readWrite", db: "app"}, {role: "dbAdmin", db: "app"}];
if (app.getUser("app")) { app.updateUser("app", {pwd: %q, roles}); } else { app.createUser({user: "app", pwd: %q, roles}); }`, password, password)
	out, err := exec.Command("mongosh", "--quiet", "--port", "27017", "-u", "dply_admin", "-p", m.admin,
		"--authenticationDatabase", "admin", "--eval", script).CombinedOutput()
	if err != nil {
		return fmt.Errorf("mongosh: %v: %s", err, lastLines(string(out), 3))
	}
	return nil
}

func (m *mongo) restore(target string) error {
	if m.backups == nil {
		return fmt.Errorf("backups are not configured")
	}
	return m.backups.restore(target)
}

func (m *mongo) adminArgs(args ...string) []string {
	return append([]string{"--port", "27017", "-u", "dply_admin", "-p", m.admin, "--authenticationDatabase", "admin"}, args...)
}

func (m *mongo) dump(w io.Writer) error {
	// Taken before the dump: replaying from here re-applies entries the dump
	// may already hold, which the oplog's idempotent form allows.
	m.dumpPos = ""
	newest, err := m.newestTS()
	if err != nil {
		return err
	}
	cmd := exec.Command("mongodump", m.adminArgs("--db", "app", "--archive", "--quiet")...)
	cmd.Stdout = w
	if err := runCaptured(cmd); err != nil {
		return err
	}
	m.dumpPos = newest.String()
	return nil
}

func (m *mongo) dumpPosition() string { return m.dumpPos }

func (m *mongo) eval(js string) (string, error) {
	cmd := exec.Command("mongosh", m.adminArgs("--quiet", "--eval", js)...)
	var stderr strings.Builder
	cmd.Stderr = &stderr
	out, err := cmd.Output()
	if err != nil {
		return "", fmt.Errorf("mongosh: %v: %s", err, lastLines(stderr.String(), 3))
	}
	return strings.TrimSpace(string(out)), nil
}

func (m *mongo) newestTS() (oplogTS, error) {
	out, err := m.eval(`const e = db.getSiblingDB("local").oplog.rs.find({}, {ts: 1}).sort({$natural: -1}).limit(1).next(); print(e.ts.t + "-" + e.ts.i)`)
	if err != nil {
		return oplogTS{}, err
	}
	return parseTS(lastLines(out, 1))
}

func tsQuery(after, upTo oplogTS) string {
	return fmt.Sprintf(`{"ts": {"$gt": {"$timestamp": {"t": %d, "i": %d}}, "$lte": {"$timestamp": {"t": %d, "i": %d}}}, "op": {"$ne": "n"}}`, after.T, after.I, upTo.T, upTo.I)
}

// ship uploads oplog entries since the last call as one chunk named by its
// newest entry. The periodic no-op entries of an idle set are skipped, so an
// idle database uploads nothing.
func (m *mongo) ship(d *dumps) error {
	var shipped oplogTS
	if b, err := os.ReadFile(m.shippedFile()); err == nil {
		shipped, _ = parseTS(strings.TrimSpace(string(b)))
	}
	newest, err := m.newestTS()
	if err != nil || !newest.after(shipped) {
		return err
	}
	count, err := m.eval(fmt.Sprintf(`print(db.getSiblingDB("local").oplog.rs.countDocuments(EJSON.parse(%q)))`, tsQuery(shipped, newest)))
	if err != nil {
		return err
	}
	if lastLines(count, 1) != "0" {
		dir := filepath.Join(m.run, "ship")
		_ = os.RemoveAll(dir)
		defer os.RemoveAll(dir)
		cmd := exec.Command("mongodump", m.adminArgs("--db", "local", "--collection", "oplog.rs", "--query", tsQuery(shipped, newest), "--out", dir, "--quiet")...)
		if err := runCaptured(cmd); err != nil {
			return err
		}
		gz := filepath.Join(m.run, "oplog.bson.gz")
		defer os.Remove(gz)
		if err := gzipFile(filepath.Join(dir, "local", "oplog.rs.bson"), gz); err != nil {
			return err
		}
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
		defer cancel()
		if err := d.putFile(ctx, d.prefix+"oplog/"+newest.String()+".bson.gz", gz); err != nil {
			return err
		}
	}
	return os.WriteFile(m.shippedFile(), []byte(newest.String()), 0o600)
}

// replay applies oplog entries after from, up to the end of target's second.
func (m *mongo) replay(d *dumps, from, target string) error {
	start, err := parseTS(from)
	if err != nil {
		return err
	}
	limit := oplogTS{T: ^uint32(0), I: ^uint32(0)}
	if target != "" {
		at, err := time.Parse(time.RFC3339, target)
		if err != nil {
			return err
		}
		limit = oplogTS{T: uint32(at.Unix()), I: ^uint32(0)}
	}
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Minute)
	defer cancel()
	keys, err := d.keys(ctx, "oplog/")
	if err != nil {
		return err
	}
	dir := filepath.Join(m.run, "replay")
	_ = os.RemoveAll(dir)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	defer os.RemoveAll(dir)
	out, err := os.Create(filepath.Join(dir, "oplog.bson"))
	if err != nil {
		return err
	}
	entries := 0
	for _, k := range keys {
		end, err := parseTS(strings.TrimSuffix(filepath.Base(k), ".bson.gz"))
		if err != nil || !end.after(start) {
			continue // wholly before the dump
		}
		obj, err := d.client.GetObject(ctx, d.bucket, k, minio.GetObjectOptions{})
		if err != nil {
			return err
		}
		zr, err := gzip.NewReader(obj)
		if err != nil {
			obj.Close()
			return fmt.Errorf("%s: %v", k, err)
		}
		err = readDocs(zr, func(doc []byte) error {
			ts, err := docTS(doc)
			if err != nil || !ts.after(start) || ts.after(limit) {
				return err
			}
			entries++
			_, err = out.Write(doc)
			return err
		})
		obj.Close()
		if err != nil {
			return fmt.Errorf("%s: %v", k, err)
		}
	}
	if err := out.Close(); err != nil {
		return err
	}
	if entries == 0 {
		return nil
	}
	log.Printf("restore: replaying %d oplog entries", entries)
	return runCaptured(exec.Command("mongorestore", m.adminArgs("--oplogReplay", "--quiet", dir)...))
}

// prune removes oplog chunks that end at or before before.
func (m *mongo) prune(d *dumps, before string) error {
	cut, err := parseTS(before)
	if err != nil {
		return nil
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
	defer cancel()
	keys, err := d.keys(ctx, "oplog/")
	if err != nil {
		return err
	}
	for _, k := range keys {
		if end, err := parseTS(strings.TrimSuffix(filepath.Base(k), ".bson.gz")); err == nil && !end.after(cut) {
			if err := d.remove(ctx, k); err != nil {
				return err
			}
		}
	}
	return nil
}

func gzipFile(src, dst string) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	out, err := os.Create(dst)
	if err != nil {
		return err
	}
	zw := gzip.NewWriter(out)
	if _, err := io.Copy(zw, in); err != nil {
		out.Close()
		return err
	}
	if err := zw.Close(); err != nil {
		out.Close()
		return err
	}
	return out.Close()
}

// load drops "app" first so collections created after the dump go too. The
// app's login lives on "app" but survives the drop (users are in admin).
func (m *mongo) load(r io.Reader) error {
	drop := exec.Command("mongosh", m.adminArgs("--quiet", "--eval", `db.getSiblingDB("app").dropDatabase()`)...)
	if err := runCaptured(drop); err != nil {
		return err
	}
	cmd := exec.Command("mongorestore", m.adminArgs("--archive", "--nsInclude", "app.*", "--quiet")...)
	cmd.Stdin = r
	return runCaptured(cmd)
}
