package main

import (
	"bufio"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"
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
