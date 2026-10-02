// dply-agent is PID 1 in a dply container app (T-037, ruling
// r-5w7h5d0b902aeq0n). It runs the app's command (everything after --) as
// its child, forwards signals to it, reaps orphaned processes, and exits with
// the app's exit code, so the app behaves as if it ran alone.
//
// Beside it, an HTTP server on DPLY_AGENT_PORT runs one-off commands for the
// dply dashboard and streams their output (POST /exec). Every request needs
// the app's DPLY_QUEUE_TOKEN; with no token the server stays off. Nothing the
// server does can stop the app: a failure there is logged and the app runs on.
//
// Linux only (Wait4, process groups): it runs inside the app's image.
package main

import (
	"fmt"
	"log"
	"os"
	"os/exec"
	"os/signal"
	"sync"
	"syscall"
)

// defaultPort is unusual on purpose: an app's own server is never on it
// (EdgeContainerAgent skips the agent when the app's port matches).
const defaultPort = "7879"

func main() {
	args := os.Args[1:]
	if len(args) > 0 && args[0] == "--" {
		args = args[1:]
	}
	if len(args) == 0 {
		fmt.Fprintln(os.Stderr, "usage: dply-agent -- command [args...]")
		os.Exit(2)
	}
	r := newReaper()
	go r.loop()
	go serve(r, env("DPLY_AGENT_PORT", defaultPort), os.Getenv("DPLY_QUEUE_TOKEN"))

	sigs := make(chan os.Signal, 8)
	signal.Notify(sigs, syscall.SIGTERM, syscall.SIGINT, syscall.SIGHUP, syscall.SIGQUIT, syscall.SIGUSR1, syscall.SIGUSR2, syscall.SIGWINCH)
	os.Exit(runMain(r, args, sigs))
}

// runMain starts the app's command with the agent's stdio and environment,
// passes every signal on, and returns the code to exit with: the app's own,
// or 128+signal when a signal ended it (a shell's convention).
func runMain(r *reaper, args []string, sigs <-chan os.Signal) int {
	cmd := exec.Command(args[0], args[1:]...)
	cmd.Stdin, cmd.Stdout, cmd.Stderr = os.Stdin, os.Stdout, os.Stderr
	exited, err := r.start(cmd)
	if err != nil {
		log.Printf("dply-agent: %v", err)
		return 127
	}
	for {
		select {
		case sig := <-sigs:
			_ = cmd.Process.Signal(sig)
		case ws := <-exited:
			return exitCode(ws)
		}
	}
}

func exitCode(ws syscall.WaitStatus) int {
	if ws.Signaled() {
		return 128 + int(ws.Signal())
	}
	return ws.ExitStatus()
}

// reaper is the one place that collects exited children. As PID 1 the agent
// inherits every orphan, and a second collector (exec.Cmd.Wait) would race
// it for exit statuses, so commands are started through start() and waited
// on through the channel start() returns, never cmd.Wait().
type reaper struct {
	mu      sync.Mutex
	waiting map[int]chan syscall.WaitStatus
	sigchld chan os.Signal
}

func newReaper() *reaper {
	r := &reaper{waiting: map[int]chan syscall.WaitStatus{}, sigchld: make(chan os.Signal, 1)}
	signal.Notify(r.sigchld, syscall.SIGCHLD)
	return r
}

func (r *reaper) loop() {
	r.reap()
	for range r.sigchld {
		r.reap()
	}
}

func (r *reaper) reap() {
	for {
		var ws syscall.WaitStatus
		pid, err := syscall.Wait4(-1, &ws, syscall.WNOHANG, nil)
		if pid <= 0 || err != nil {
			return
		}
		r.mu.Lock()
		if ch, ok := r.waiting[pid]; ok {
			delete(r.waiting, pid)
			ch <- ws
		}
		// Anything else is an orphan the app left behind: collected, dropped.
		r.mu.Unlock()
	}
}

// start runs cmd and registers it under the lock reap() takes, so its exit
// can never be collected before it is known to be ours. The channel gets its
// exit status.
func (r *reaper) start(cmd *exec.Cmd) (<-chan syscall.WaitStatus, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if err := cmd.Start(); err != nil {
		return nil, err
	}
	ch := make(chan syscall.WaitStatus, 1)
	r.waiting[cmd.Process.Pid] = ch
	return ch, nil
}

func env(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
