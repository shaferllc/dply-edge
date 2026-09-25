package main

import (
	"context"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// mysqlEngine runs mysqld as a daemonized child of dbagent. root's password
// is AGENT_TOKEN (local socket only matters here). The app logs in as "app"
// on database "app" through the gateway, which terminates client auth and
// logs in here with mysql_native_password over the pod network (see the
// gateway's mysql.go); that plugin is enabled for that reason only.
// Backups are daily dumps plus the binlog, shipped each minute (dump.go),
// so a restore can go to any second.
type mysqlEngine struct {
	data, run, admin string
	backups          *dumps
	dumpPos          string // binlog position of the last dump ("file pos")
	flushedAt        string // binlog status right after the last flush
}

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
		// The binlog is the point-in-time log; it is shipped and purged each
		// minute. The expiry only caps it if shipping stops, so it cannot fill the disk.
		"--server-id=1", "--log-bin=binlog", "--binlog-expire-logs-seconds=259200",
		"--innodb-buffer-pool-size=" + bufferPool()}, extra...)
	if err := run("mysqld", args...); err != nil {
		tail, _ := os.ReadFile(m.logFile())
		return fmt.Errorf("%v; mysqld: %s", err, lastLines(string(tail), 3))
	}
	return nil
}

// query runs one statement as root and returns its rows, tab-separated.
func (m *mysqlEngine) query(q string) (string, error) {
	cmd := exec.Command("mysql", "--socket="+m.socket(), "-uroot", "-p"+m.admin, "-N", "-B", "-e", q)
	var stderr strings.Builder
	cmd.Stderr = &stderr
	out, err := cmd.Output()
	if err != nil {
		return "", fmt.Errorf("mysql: %v: %s", err, lastLines(stderr.String(), 3))
	}
	return strings.TrimSpace(string(out)), nil
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

func (m *mysqlEngine) restore(target string) error {
	if m.backups == nil {
		return fmt.Errorf("backups are not configured")
	}
	return m.backups.restore(target)
}

// dump is "app" only: the app's login and grants live in mysql.*, so a load
// never changes them.
func (m *mysqlEngine) dump(w io.Writer) error {
	// --source-data=2 writes the binlog position the dump is consistent with
	// as a comment near the top; posCapture reads it on the way through.
	capture := &posCapture{w: w}
	cmd := exec.Command("mysqldump", "--socket="+m.socket(), "-uroot", "-p"+m.admin,
		"--single-transaction", "--source-data=2", "--routines", "--triggers", "--events", "--set-gtid-purged=OFF", "app")
	cmd.Stdout = capture
	m.dumpPos = ""
	if err := runCaptured(cmd); err != nil {
		return err
	}
	m.dumpPos = capture.pos
	return nil
}

var sourcePos = regexp.MustCompile(`SOURCE_LOG_FILE='([^']+)', SOURCE_LOG_POS=(\d+)`)

type posCapture struct {
	w    io.Writer
	head []byte
	pos  string
}

func (p *posCapture) Write(b []byte) (int, error) {
	if p.pos == "" && len(p.head) < 1<<16 {
		p.head = append(p.head, b...)
		if f := sourcePos.FindSubmatch(p.head); f != nil {
			p.pos = string(f[1]) + " " + string(f[2])
		}
	}
	return p.w.Write(b)
}

func (m *mysqlEngine) dumpPosition() string { return m.dumpPos }

func (m *mysqlEngine) activeQueries() (int, error) {
	if !m.running() {
		return 0, nil
	}
	out, err := m.query("SELECT COUNT(*) FROM performance_schema.processlist WHERE COMMAND = 'Query' AND USER = 'app'")
	if err != nil {
		return 0, err
	}
	return strconv.Atoi(out)
}

func (m *mysqlEngine) shippedFile() string {
	return filepath.Join(filepath.Dir(m.data), "binlog-shipped")
}

// ship closes the current binlog if anything was written since the last
// flush, uploads every closed binlog not yet uploaded, then purges them
// locally. An idle database uploads nothing.
func (m *mysqlEngine) ship(d *dumps) error {
	status, err := m.query("SHOW BINARY LOG STATUS")
	if err != nil {
		return err
	}
	if status != m.flushedAt {
		if err := m.sql(true, "FLUSH BINARY LOGS"); err != nil {
			return err
		}
		if m.flushedAt, err = m.query("SHOW BINARY LOG STATUS"); err != nil {
			return err
		}
	}
	list, err := m.query("SHOW BINARY LOGS")
	if err != nil {
		return err
	}
	var names []string
	for _, line := range strings.Split(list, "\n") {
		if f := strings.Fields(line); len(f) > 0 {
			names = append(names, f[0])
		}
	}
	if len(names) < 2 {
		return nil // only the open one
	}
	b, _ := os.ReadFile(m.shippedFile())
	shipped := strings.TrimSpace(string(b))
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
	defer cancel()
	current := names[len(names)-1]
	for _, name := range names[:len(names)-1] {
		if name <= shipped {
			continue
		}
		if err := d.putFile(ctx, d.prefix+"binlog/"+name, filepath.Join(m.data, name)); err != nil {
			return fmt.Errorf("upload %s: %v", name, err)
		}
		if err := os.WriteFile(m.shippedFile(), []byte(name), 0o600); err != nil {
			return err
		}
	}
	return m.sql(true, "PURGE BINARY LOGS TO '"+current+"'")
}

// replay applies the app's binlog events after from ("file pos") up to
// target. They are not logged again (sql_log_bin=0); the restore takes a
// fresh dump as the new base instead.
func (m *mysqlEngine) replay(d *dumps, from, target string) error {
	f := strings.Fields(from)
	if len(f) != 2 {
		return fmt.Errorf("bad position %q", from)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Minute)
	defer cancel()
	keys, err := d.keys(ctx, "binlog/")
	if err != nil {
		return err
	}
	dir := filepath.Join(m.run, "replay")
	_ = os.RemoveAll(dir)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	defer os.RemoveAll(dir)
	var files []string
	for _, k := range keys {
		name := filepath.Base(k)
		if name < f[0] {
			continue
		}
		path := filepath.Join(dir, name)
		if err := d.getFile(ctx, k, path); err != nil {
			return fmt.Errorf("download %s: %v", name, err)
		}
		files = append(files, path)
	}
	if len(files) == 0 {
		return nil // nothing was written after the dump
	}
	args := []string{"--database=app"}
	if filepath.Base(files[0]) == f[0] {
		args = append(args, "--start-position="+f[1])
	}
	if target != "" {
		at, err := time.Parse(time.RFC3339, target)
		if err != nil {
			return err
		}
		// Inclusive of the target second.
		args = append(args, "--stop-datetime="+at.UTC().Add(time.Second).Format("2006-01-02 15:04:05"))
	}
	decode := exec.Command("mysqlbinlog", append(args, files...)...)
	decode.Env = append(os.Environ(), "TZ=UTC")
	apply := exec.Command("mysql", "--socket="+m.socket(), "-uroot", "-p"+m.admin, "--init-command=SET sql_log_bin=0")
	pipe, err := decode.StdoutPipe()
	if err != nil {
		return err
	}
	apply.Stdin = pipe
	var decodeErr strings.Builder
	decode.Stderr = &decodeErr
	if err := decode.Start(); err != nil {
		return err
	}
	applyErr := runCaptured(apply)
	if err := decode.Wait(); err != nil {
		return fmt.Errorf("mysqlbinlog: %v: %s", err, lastLines(decodeErr.String(), 3))
	}
	return applyErr
}

// prune removes uploaded binlogs older than before's file.
func (m *mysqlEngine) prune(d *dumps, before string) error {
	f := strings.Fields(before)
	if len(f) != 2 {
		return nil
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
	defer cancel()
	keys, err := d.keys(ctx, "binlog/")
	if err != nil {
		return err
	}
	for _, k := range keys {
		if filepath.Base(k) < f[0] {
			if err := d.remove(ctx, k); err != nil {
				return err
			}
		}
	}
	return nil
}

// load recreates "app" empty first so tables created after the dump go too.
func (m *mysqlEngine) load(r io.Reader) error {
	// Not logged: the restore's fresh dump is the new base, not these writes.
	if err := m.sql(true, "SET sql_log_bin=0; DROP DATABASE IF EXISTS app; CREATE DATABASE app"); err != nil {
		return err
	}
	cmd := exec.Command("mysql", "--socket="+m.socket(), "-uroot", "-p"+m.admin, "--init-command=SET sql_log_bin=0", "app")
	cmd.Stdin = r
	return runCaptured(cmd)
}
