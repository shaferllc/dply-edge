package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// mysqlEngine runs mysqld as a daemonized child of dbagent. root's password
// is AGENT_TOKEN (local socket only matters here). The app logs in as "app"
// on database "app" through the gateway, which terminates client auth and
// logs in here with mysql_native_password over the pod network (see the
// gateway's mysql.go); that plugin is enabled for that reason only.
type mysqlEngine struct{ data, run, admin string }

func (m *mysqlEngine) marker() string  { return filepath.Join(m.data, ".dply-initialized") }
func (m *mysqlEngine) pidFile() string { return filepath.Join(m.run, "mysqld.pid") }
func (m *mysqlEngine) socket() string  { return filepath.Join(m.run, "mysqld.sock") }
func (m *mysqlEngine) logFile() string { return filepath.Join(m.run, "mysqld.log") }

func (m *mysqlEngine) initialized() bool {
	_, err := os.Stat(m.marker())
	return err == nil
}

// bufferPool is a quarter of the pod's memory limit (cgroup v2), at least 128 MB.
func bufferPool() string {
	bytes := float64(128 << 20)
	if b, err := os.ReadFile("/sys/fs/cgroup/memory.max"); err == nil {
		if n, err := strconv.ParseFloat(strings.TrimSpace(string(b)), 64); err == nil {
			bytes = max(bytes, n/4)
		}
	}
	return strconv.FormatInt(int64(bytes), 10)
}

func (m *mysqlEngine) mysqld(extra ...string) error {
	args := append([]string{"--daemonize", "--datadir=" + m.data, "--socket=" + m.socket(), "--pid-file=" + m.pidFile(),
		"--log-error=" + m.logFile(), "--mysqlx=OFF", "--skip-name-resolve", "--mysql-native-password=ON",
		"--innodb-buffer-pool-size=" + bufferPool()}, extra...)
	if err := run("mysqld", args...); err != nil {
		tail, _ := os.ReadFile(m.logFile())
		return fmt.Errorf("%v; mysqld: %s", err, lastLines(string(tail), 3))
	}
	return nil
}

func (m *mysqlEngine) sql(asRoot bool, query string) error {
	args := []string{"--socket=" + m.socket(), "-uroot", "-e", query}
	if asRoot {
		args = append([]string{"-p" + m.admin}, args...)
	}
	out, err := exec.Command("mysql", args...).CombinedOutput()
	if err != nil {
		return fmt.Errorf("mysql: %v: %s", err, lastLines(string(out), 3))
	}
	return nil
}

func (m *mysqlEngine) init() error {
	for _, dir := range []string{m.data, m.run} {
		if err := os.MkdirAll(dir, 0o750); err != nil {
			return err
		}
	}
	// mysqld --initialize wants an empty data directory; the volume root has lost+found.
	if entries, _ := os.ReadDir(m.data); len(entries) == 0 {
		if err := run("mysqld", "--initialize-insecure", "--datadir="+m.data, "--log-error="+m.logFile()); err != nil {
			tail, _ := os.ReadFile(m.logFile())
			return fmt.Errorf("%v; mysqld: %s", err, lastLines(string(tail), 3))
		}
	}
	if err := m.mysqld("--skip-networking"); err != nil {
		return err
	}
	quoted := "'" + strings.ReplaceAll(m.admin, "'", "''") + "'"
	if err := m.sql(false, "ALTER USER 'root'@'localhost' IDENTIFIED BY "+quoted); err != nil {
		_ = m.stop()
		return err
	}
	if err := m.stop(); err != nil {
		return err
	}
	return os.WriteFile(m.marker(), []byte(time.Now().UTC().Format(time.RFC3339)), 0o600)
}

func (m *mysqlEngine) running() bool { return pidRunning(m.pidFile()) }

func (m *mysqlEngine) start() error {
	if err := os.MkdirAll(m.run, 0o750); err != nil {
		return err
	}
	if m.running() {
		return nil
	}
	if err := m.mysqld("--bind-address=0.0.0.0", "--port=3306"); err != nil {
		return err
	}
	// --daemonize returns once the server accepts connections on the socket.
	return nil
}

func (m *mysqlEngine) stop() error {
	if !m.running() {
		return nil
	}
	if err := run("mysqladmin", "--socket="+m.socket(), "-uroot", "-p"+m.admin, "shutdown"); err != nil {
		// Before root has a password (first start) it has none.
		if err2 := run("mysqladmin", "--socket="+m.socket(), "-uroot", "shutdown"); err2 != nil {
			return err
		}
	}
	// Wait for it to exit (and be reaped) so the next start never races it.
	for i := 0; i < 120 && pidRunning(m.pidFile()); i++ {
		time.Sleep(500 * time.Millisecond)
	}
	return nil
}

func (m *mysqlEngine) setTenant(password string) error {
	q := "'" + strings.ReplaceAll(strings.ReplaceAll(password, `\`, `\\`), "'", "''") + "'"
	return m.sql(true, "CREATE DATABASE IF NOT EXISTS app; "+
		"CREATE USER IF NOT EXISTS 'app'@'%' IDENTIFIED WITH mysql_native_password BY "+q+"; "+
		"ALTER USER 'app'@'%' IDENTIFIED WITH mysql_native_password BY "+q+"; "+
		"GRANT ALL PRIVILEGES ON app.* TO 'app'@'%'")
}

func (m *mysqlEngine) restore(string) error {
	return fmt.Errorf("restore is not available for MySQL yet")
}
