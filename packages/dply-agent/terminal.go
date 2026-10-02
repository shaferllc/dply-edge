package main

import (
	"context"
	"encoding/json"
	"log"
	"net/http"
	"os"
	"os/exec"
	"strconv"
	"syscall"
	"time"

	"github.com/coder/websocket"
	"github.com/creack/pty"
)

// GET /terminal (T-041): an interactive shell for dply's operators, over a
// WebSocket. The shell runs in a pseudo-terminal in the app's directory and
// environment, started through the reaper like every other child. Messages
// from the browser are JSON: {"t":"i","d":"…"} keystrokes, {"t":"r","c":cols,
// "r":rows} a resize. Output goes back as binary frames.
//
// Every session and everything typed into it is written to the agent's own
// stderr ("dply-agent: terminal …"), so it lands in the app's logs, where the
// customer sees it too. A session ends after terminalIdle without input, at
// terminalMax, or when either side closes.

const (
	terminalIdle = 15 * time.Minute
	terminalMax  = 30 * time.Minute
)

type terminalMessage struct {
	T string `json:"t"`
	D string `json:"d"`
	C uint16 `json:"c"`
	R uint16 `json:"r"`
}

func terminalHandler(r *reaper) http.HandlerFunc {
	return func(w http.ResponseWriter, req *http.Request) {
		conn, err := websocket.Accept(w, req, &websocket.AcceptOptions{InsecureSkipVerify: true}) // the token already checked who this is
		if err != nil {
			return
		}
		defer conn.CloseNow()
		conn.SetReadLimit(1 << 20)

		id := strconv.FormatInt(time.Now().UnixNano(), 36)
		who := req.Header.Get("x-dply-operator")
		ptmx, tty, err := pty.Open()
		if err != nil {
			_ = conn.Close(websocket.StatusInternalError, "no pseudo-terminal: "+err.Error())
			return
		}
		defer ptmx.Close()
		cols, rows := uint16(120), uint16(32)
		if c, _ := strconv.Atoi(req.URL.Query().Get("cols")); c > 0 {
			cols = uint16(c)
		}
		if rr, _ := strconv.Atoi(req.URL.Query().Get("rows")); rr > 0 {
			rows = uint16(rr)
		}
		_ = pty.Setsize(ptmx, &pty.Winsize{Cols: cols, Rows: rows})

		cmd := exec.Command(shell())
		cmd.Env = append(os.Environ(), "TERM=xterm-256color")
		cmd.Stdin, cmd.Stdout, cmd.Stderr = tty, tty, tty
		cmd.SysProcAttr = &syscall.SysProcAttr{Setsid: true, Setctty: true}
		exited, err := r.start(cmd)
		_ = tty.Close()
		if err != nil {
			_ = conn.Close(websocket.StatusInternalError, "could not start a shell: "+err.Error())
			return
		}
		log.Printf("dply-agent: terminal %s opened by %q (%s)", id, who, shell())

		ctx, cancel := context.WithTimeout(req.Context(), terminalMax)
		defer cancel()
		go func() { // shell output -> browser
			buf := make([]byte, 32<<10)
			for {
				n, err := ptmx.Read(buf)
				if n > 0 && conn.Write(ctx, websocket.MessageBinary, buf[:n]) != nil {
					break
				}
				if err != nil {
					break
				}
			}
			cancel()
		}()
		go func() { // the shell exited
			<-exited
			cancel()
		}()

		for {
			readCtx, readCancel := context.WithTimeout(ctx, terminalIdle)
			_, data, err := conn.Read(readCtx)
			readCancel()
			if err != nil {
				break
			}
			var msg terminalMessage
			if json.Unmarshal(data, &msg) != nil {
				continue
			}
			switch msg.T {
			case "i":
				log.Printf("dply-agent: terminal %s input %q", id, msg.D)
				_, _ = ptmx.Write([]byte(msg.D))
			case "r":
				if msg.C > 0 && msg.R > 0 {
					_ = pty.Setsize(ptmx, &pty.Winsize{Cols: msg.C, Rows: msg.R})
				}
			}
		}
		// The whole session group goes: the shell and anything it started.
		_ = syscall.Kill(-cmd.Process.Pid, syscall.SIGHUP)
		select {
		case <-exited:
		case <-time.After(killGrace):
			_ = syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
		}
		log.Printf("dply-agent: terminal %s closed", id)
		_ = conn.Close(websocket.StatusNormalClosure, "session ended")
	}
}

// shell: bash when the image has it, else sh.
func shell() string {
	for _, s := range []string{"/bin/bash", "/bin/sh"} {
		if _, err := os.Stat(s); err == nil {
			return s
		}
	}
	return "sh"
}
