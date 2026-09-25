package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// mongo runs mongod as a forked child of dbagent. The admin user
// (dply_admin, password AGENT_TOKEN) is created once with auth off and bound
// to localhost; every later start has --auth on all interfaces. The app logs
// in as "app" on database "app". No backups yet: wal-g's MongoDB support
// needs a replica set.
type mongo struct{ data, run, admin string }

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
	args := append([]string{"--dbpath", m.data, "--port", "27017", "--fork", "--logpath", m.logFile(), "--logappend",
		"--pidfilepath", m.pidFile(), "--wiredTigerCacheSizeGB", cacheGB()}, extra...)
	return run("mongod", args...)
}

func (m *mongo) init() error {
	for _, dir := range []string{m.data, m.run} {
		if err := os.MkdirAll(dir, 0o700); err != nil {
			return err
		}
	}
	if err := m.mongod("--bind_ip", "127.0.0.1"); err != nil {
		return err
	}
	create := fmt.Sprintf(`db.getSiblingDB("admin").createUser({user: "dply_admin", pwd: %q, roles: ["root"]})`, m.admin)
	if err := run("mongosh", "--quiet", "--port", "27017", "--eval", create); err != nil {
		_ = m.stop()
		return err
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
	if err := m.mongod("--bind_ip_all", "--auth"); err != nil {
		tail, _ := os.ReadFile(m.logFile())
		return fmt.Errorf("%v; mongod: %s", err, lastLines(string(tail), 3))
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

func (m *mongo) restore(string) error {
	return fmt.Errorf("restore is not available for MongoDB yet")
}
