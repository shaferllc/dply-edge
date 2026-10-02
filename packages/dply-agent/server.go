package main

import (
	"crypto/subtle"
	"encoding/json"
	"io"
	"log"
	"net/http"
	"os"
	"os/exec"
	"sync"
	"syscall"
	"time"
)

const (
	defaultTimeout = 300 * time.Second
	maxTimeout     = time.Hour
	// Output past this is read and dropped, and the result says so.
	maxOutput = 1 << 20
	// Commands at once; more answer 429.
	maxRunning = 4
	// After SIGTERM, how long a command gets before SIGKILL.
	killGrace = 5 * time.Second
)

// serve runs the command server until it fails. It never exits the process.
func serve(r *reaper, port, token string) {
	if token == "" {
		log.Printf("dply-agent: no DPLY_QUEUE_TOKEN, commands are off")
		return
	}
	if err := http.ListenAndServe(":"+port, handler(r, token)); err != nil {
		log.Printf("dply-agent: command server stopped: %v", err)
	}
}

// handler: GET /health, POST /exec. Every route needs the token, compared in
// constant time. New routes (processes, env, terminal) go here.
func handler(r *reaper, token string) http.Handler {
	running := make(chan struct{}, maxRunning)
	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", func(w http.ResponseWriter, _ *http.Request) { _, _ = io.WriteString(w, "ok") })
	mux.HandleFunc("POST /exec", func(w http.ResponseWriter, req *http.Request) {
		select {
		case running <- struct{}{}:
			defer func() { <-running }()
		default:
			http.Error(w, "too many commands running", http.StatusTooManyRequests)
			return
		}
		execCommand(r, w, req)
	})
	return http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		if subtle.ConstantTimeCompare([]byte(req.Header.Get("x-dply-queue-token")), []byte(token)) != 1 {
			http.Error(w, "forbidden", http.StatusForbidden)
			return
		}
		mux.ServeHTTP(w, req)
	})
}

type execRequest struct {
	Command string   `json:"command"` // run with /bin/sh -c
	Argv    []string `json:"argv"`    // or exactly this, for images without a shell
	Timeout int      `json:"timeout"` // seconds; default 300, at most 3600
}

// execCommand runs one command in its own process group, in the app's
// directory and environment, and streams NDJSON lines: {"out": "..."} and
// {"err": "..."} as output arrives, then {"exit": n, "seconds": s,
// "truncated": bool, "timed_out": bool}. The command is killed when the
// timeout passes or the caller goes away; the timeout is the backstop, since
// a disconnect may not reach this far.
func execCommand(r *reaper, w http.ResponseWriter, req *http.Request) {
	var body execRequest
	if err := json.NewDecoder(io.LimitReader(req.Body, 64<<10)).Decode(&body); err != nil {
		http.Error(w, "send JSON: {\"command\": \"...\"}", http.StatusBadRequest)
		return
	}
	argv := body.Argv
	if len(argv) == 0 {
		if body.Command == "" {
			http.Error(w, "command is required", http.StatusUnprocessableEntity)
			return
		}
		argv = []string{"/bin/sh", "-c", body.Command}
	}
	timeout := defaultTimeout
	if body.Timeout > 0 {
		timeout = min(time.Duration(body.Timeout)*time.Second, maxTimeout)
	}

	outR, outW, err := os.Pipe()
	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	errR, errW, err := os.Pipe()
	if err != nil {
		_, _ = outR.Close(), outW.Close()
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	cmd := exec.Command(argv[0], argv[1:]...)
	cmd.Stdout, cmd.Stderr = outW, errW
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	started := time.Now()
	exited, err := r.start(cmd)
	_, _ = outW.Close(), errW.Close()
	if err != nil {
		_, _ = outR.Close(), errR.Close()
		http.Error(w, err.Error(), http.StatusUnprocessableEntity)
		return
	}

	w.Header().Set("Content-Type", "application/x-ndjson")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(http.StatusOK)
	out := &stream{w: w, flusher: asFlusher(w), left: maxOutput}

	var readers sync.WaitGroup
	readers.Add(2)
	go out.copy(&readers, "out", outR)
	go out.copy(&readers, "err", errR)

	timedOut := false
	var ws syscall.WaitStatus
	timer := time.NewTimer(timeout)
	defer timer.Stop()
	select {
	case ws = <-exited:
	case <-timer.C:
		timedOut = true
		ws = stop(cmd.Process.Pid, exited)
	case <-req.Context().Done():
		ws = stop(cmd.Process.Pid, exited)
	}
	readers.Wait() // the group is gone, so both pipes reach EOF
	out.line(map[string]any{"exit": exitCode(ws), "seconds": time.Since(started).Seconds(), "truncated": out.truncated, "timed_out": timedOut})
}

// stop ends the command's whole process group: SIGTERM, then SIGKILL.
func stop(pid int, exited <-chan syscall.WaitStatus) syscall.WaitStatus {
	_ = syscall.Kill(-pid, syscall.SIGTERM)
	select {
	case ws := <-exited:
		_ = syscall.Kill(-pid, syscall.SIGKILL) // anything it left in the group
		return ws
	case <-time.After(killGrace):
		_ = syscall.Kill(-pid, syscall.SIGKILL)
		return <-exited
	}
}

type stream struct {
	mu        sync.Mutex
	w         io.Writer
	flusher   http.Flusher
	left      int
	truncated bool
}

func (s *stream) copy(done *sync.WaitGroup, name string, from *os.File) {
	defer done.Done()
	defer from.Close()
	buf := make([]byte, 16<<10)
	for {
		n, err := from.Read(buf)
		if n > 0 {
			s.mu.Lock()
			chunk := buf[:n]
			if len(chunk) > s.left {
				chunk, s.truncated = chunk[:s.left], true
			}
			s.left -= len(chunk)
			s.mu.Unlock()
			if len(chunk) > 0 {
				s.line(map[string]any{name: string(chunk)})
			}
		}
		if err != nil {
			return
		}
	}
}

func (s *stream) line(v any) {
	b, _ := json.Marshal(v)
	s.mu.Lock()
	defer s.mu.Unlock()
	_, _ = s.w.Write(append(b, '\n'))
	if s.flusher != nil {
		s.flusher.Flush()
	}
}

func asFlusher(w http.ResponseWriter) http.Flusher {
	f, _ := w.(http.Flusher)
	return f
}
