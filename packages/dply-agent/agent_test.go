package main

import (
	"bufio"
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"

	"github.com/coder/websocket"
)

var (
	sharedReaper *reaper
	reaperOnce   sync.Once
)

// One reaper for the whole test binary, as in the real process.
func testReaper() *reaper {
	reaperOnce.Do(func() {
		sharedReaper = newReaper()
		go sharedReaper.loop()
	})
	return sharedReaper
}

func TestExitCodePassesThrough(t *testing.T) {
	if got := runMain(testReaper(), []string{"sh", "-c", "exit 3"}, make(chan os.Signal)); got != 3 {
		t.Fatalf("exit code: got %d", got)
	}
}

func TestSignalsReachTheApp(t *testing.T) {
	sigs := make(chan os.Signal, 1)
	done := make(chan int, 1)
	go func() {
		done <- runMain(testReaper(), []string{"sh", "-c", `trap "exit 7" TERM; while :; do sleep 0.05; done`}, sigs)
	}()
	time.Sleep(300 * time.Millisecond) // the trap is set
	sigs <- syscall.SIGTERM
	select {
	case got := <-done:
		if got != 7 {
			t.Fatalf("exit code after SIGTERM: got %d", got)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("the app never saw SIGTERM")
	}
}

func TestAKilledAppReports128PlusSignal(t *testing.T) {
	if got := runMain(testReaper(), []string{"sh", "-c", "kill -9 $$"}, make(chan os.Signal)); got != 137 {
		t.Fatalf("exit code: got %d", got)
	}
}

func execLines(t *testing.T, srv *httptest.Server, token, body string) (int, []map[string]any) {
	t.Helper()
	req, _ := http.NewRequest(http.MethodPost, srv.URL+"/exec", strings.NewReader(body))
	req.Header.Set("x-dply-queue-token", token)
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer res.Body.Close()
	var lines []map[string]any
	scan := bufio.NewScanner(res.Body)
	for scan.Scan() {
		var line map[string]any
		if json.Unmarshal(scan.Bytes(), &line) == nil {
			lines = append(lines, line)
		}
	}
	return res.StatusCode, lines
}

func TestExecNeedsTheToken(t *testing.T) {
	srv := httptest.NewServer(handler(testReaper(), "secret"))
	defer srv.Close()
	if code, _ := execLines(t, srv, "wrong", `{"command":"echo hi"}`); code != http.StatusForbidden {
		t.Fatalf("status: got %d", code)
	}
}

func TestExecStreamsOutputAndTheExitCode(t *testing.T) {
	srv := httptest.NewServer(handler(testReaper(), "secret"))
	defer srv.Close()
	code, lines := execLines(t, srv, "secret", `{"command":"echo hello; echo oops >&2; exit 4"}`)
	if code != http.StatusOK {
		t.Fatalf("status: got %d", code)
	}
	var out, errOut string
	for _, l := range lines {
		if s, ok := l["out"].(string); ok {
			out += s
		}
		if s, ok := l["err"].(string); ok {
			errOut += s
		}
	}
	last := lines[len(lines)-1]
	if out != "hello\n" || errOut != "oops\n" || last["exit"] != float64(4) || last["timed_out"] != false {
		t.Fatalf("got out=%q err=%q last=%v", out, errOut, last)
	}
}

func TestExecTimesOutAndKillsTheGroup(t *testing.T) {
	srv := httptest.NewServer(handler(testReaper(), "secret"))
	defer srv.Close()
	started := time.Now()
	_, lines := execLines(t, srv, "secret", `{"command":"sleep 30 & sleep 30","timeout":1}`)
	last := lines[len(lines)-1]
	if last["timed_out"] != true || time.Since(started) > 10*time.Second {
		t.Fatalf("got %v after %s", last, time.Since(started))
	}
}

func TestExecCapsOutput(t *testing.T) {
	srv := httptest.NewServer(handler(testReaper(), "secret"))
	defer srv.Close()
	_, lines := execLines(t, srv, "secret", `{"command":"head -c 3000000 /dev/zero | tr '\\0' a"}`)
	total := 0
	for _, l := range lines {
		if s, ok := l["out"].(string); ok {
			total += len(s)
		}
	}
	if last := lines[len(lines)-1]; last["truncated"] != true || total != maxOutput {
		t.Fatalf("got %d bytes, last=%v", total, last)
	}
}

func TestParseStatCountsFromTheLastParenthesis(t *testing.T) {
	stat := []byte("42 (php (worker) 1) S 1 42 42 0 -1 0 0 0 0 0 250 50 0 0 20 0 1 0 1000 123456 300 18446744073709551615")
	p, ok := parseStat(stat, 30, 4096)
	if !ok || p.Name != "php (worker) 1" || p.State != "S" || p.PPID != 1 || p.CPUSeconds != 3 || p.AgeSeconds != 20 || p.RSSBytes != 300*4096 {
		t.Fatalf("got %+v ok=%v", p, ok)
	}
}

func TestProcessesAndEnvNeedTheToken(t *testing.T) {
	srv := httptest.NewServer(handler(testReaper(), "secret"))
	defer srv.Close()
	for _, path := range []string{"/processes", "/env"} {
		res, err := http.Get(srv.URL + path)
		if err != nil {
			t.Fatal(err)
		}
		res.Body.Close()
		if res.StatusCode != http.StatusForbidden {
			t.Fatalf("%s without token: %d", path, res.StatusCode)
		}
	}
	req, _ := http.NewRequest(http.MethodGet, srv.URL+"/env", nil)
	req.Header.Set("x-dply-queue-token", "secret")
	t.Setenv("DPLY_TEST_VALUE", "visible")
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer res.Body.Close()
	var body struct{ Env map[string]string }
	_ = json.NewDecoder(res.Body).Decode(&body)
	if body.Env["DPLY_TEST_VALUE"] != "visible" {
		t.Fatalf("env: %v", body.Env["DPLY_TEST_VALUE"])
	}
}

func TestReadProcessesOnLinux(t *testing.T) {
	if _, err := os.Stat("/proc/self/stat"); err != nil {
		t.Skip("no /proc")
	}
	ps := readProcesses("/proc")
	found := false
	for _, p := range ps {
		if p.PID == os.Getpid() && p.RSSBytes > 0 && p.Command != "" {
			found = true
		}
	}
	if !found {
		t.Fatalf("this test process is not listed: %+v", ps)
	}
}

func TestTerminalRunsAShellOverAWebSocket(t *testing.T) {
	if _, err := os.Stat("/dev/ptmx"); err != nil {
		t.Skip("no pseudo-terminals here")
	}
	srv := httptest.NewServer(handler(testReaper(), "secret"))
	defer srv.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	conn, _, err := websocket.Dial(ctx, "ws"+strings.TrimPrefix(srv.URL, "http")+"/terminal", &websocket.DialOptions{HTTPHeader: http.Header{"x-dply-queue-token": {"secret"}}})
	if err != nil {
		t.Fatal(err)
	}
	defer conn.CloseNow()
	_ = conn.Write(ctx, websocket.MessageText, []byte(`{"t":"i","d":"echo dply-$((40+2))\n"}`))
	var seen string
	for !strings.Contains(seen, "dply-42") {
		_, data, err := conn.Read(ctx)
		if err != nil {
			t.Fatalf("no echo; saw %q (%v)", seen, err)
		}
		seen += string(data)
	}
	_ = conn.Write(ctx, websocket.MessageText, []byte(`{"t":"i","d":"exit\n"}`))
	for {
		if _, _, err := conn.Read(ctx); err != nil {
			break // the agent closed it when the shell exited
		}
	}
}

func TestTerminalNeedsTheToken(t *testing.T) {
	srv := httptest.NewServer(handler(testReaper(), "secret"))
	defer srv.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if _, res, err := websocket.Dial(ctx, "ws"+strings.TrimPrefix(srv.URL, "http")+"/terminal", nil); err == nil || res.StatusCode != http.StatusForbidden {
		t.Fatalf("got err=%v", err)
	}
}
