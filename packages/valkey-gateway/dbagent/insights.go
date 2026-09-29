package main

import (
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"strings"
	"sync"
	"time"
)

// Insights: what the workspace's Database panel shows beyond size and
// connections. Top queries, what is running now, index and vacuum health,
// extensions, and a read-only query console. Every engine answers in the
// same JSON shape where it can; a field an engine cannot report is left out.
//
//	GET  /insights            live when the database runs, else the snapshot
//	GET  /insights?cached=1   the snapshot only: never starts the database
//	POST /action/{name}       queries-reset, cancel, extension, readonly,
//	                          query, export, import
//
// The snapshot is written just before every stop, so a sleeping database
// still shows its last numbers and the dply app's hourly sampler never has
// to wake one to read them.
//
// Nothing here passes user input to psql, mysql or mongosh: those clients
// have shell escapes (\! and system), and this pod holds the backup keys.
// Fixed SQL goes through the clients; the console uses Go drivers as a
// read-only role, one statement at a time.
type insighter interface {
	running() bool
	insights() (map[string]any, error)
	action(name string, body []byte) (any, error)
}

const insightsFile = "/data/insights.json"

var errAsleep = errors.New("the database is asleep")

// liveInsights reads the database and saves the result as the snapshot.
func liveInsights(in insighter) (map[string]any, error) {
	out, err := in.insights()
	if err != nil {
		return nil, err
	}
	out["disk_bytes"], out["disk_used_bytes"] = diskUsage("/data")
	// Only while awake: asleep, the pod's numbers are the parked shell's.
	out["memory_bytes"], out["memory_anon_bytes"], out["memory_file_bytes"], out["memory_peak_bytes"] = memoryUsage()
	out["taken_at"] = time.Now().UTC().Format(time.RFC3339)
	out["awake"] = true
	if b, err := json.Marshal(out); err == nil {
		_ = os.WriteFile(insightsFile, b, 0o600)
	}
	return out, nil
}

// snapshotBeforeStop keeps the last numbers for while it sleeps. Best effort:
// a failed read never holds up a stop.
func snapshotBeforeStop(e engine) {
	if in, ok := e.(insighter); ok && in.running() {
		if _, err := liveInsights(in); err != nil {
			log.Printf("insights snapshot: %v", err)
		}
	}
}

func cachedInsights() map[string]any {
	out := map[string]any{}
	if b, err := os.ReadFile(insightsFile); err == nil {
		_ = json.Unmarshal(b, &out)
	}
	out["disk_bytes"], out["disk_used_bytes"] = diskUsage("/data")
	out["awake"] = false
	return out
}

// registerInsights adds the routes. lifecycle is main's lock: an action runs
// between starts and stops, never during one.
func registerInsights(mux *http.ServeMux, e engine, token string, lifecycle *sync.Mutex) {
	authed := func(r *http.Request) bool {
		got := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
		return subtle.ConstantTimeCompare([]byte(got), []byte(token)) == 1
	}
	writeJSON := func(w http.ResponseWriter, v any) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(v)
	}
	mux.HandleFunc("GET /insights", func(w http.ResponseWriter, r *http.Request) {
		if !authed(r) {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		in, ok := e.(insighter)
		if !ok {
			http.Error(w, "not available for this engine", http.StatusNotFound)
			return
		}
		if r.URL.Query().Get("cached") == "1" || !in.running() {
			writeJSON(w, cachedInsights())
			return
		}
		out, err := liveInsights(in)
		if err != nil {
			http.Error(w, err.Error(), http.StatusServiceUnavailable)
			return
		}
		writeJSON(w, out)
	})
	mux.HandleFunc("POST /action/{name}", func(w http.ResponseWriter, r *http.Request) {
		if !authed(r) {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
		in, ok := e.(insighter)
		if !ok {
			http.Error(w, "not available for this engine", http.StatusNotFound)
			return
		}
		body, _ := io.ReadAll(io.LimitReader(r.Body, 1<<20))
		name := r.PathValue("name")
		lifecycle.Lock()
		defer lifecycle.Unlock()
		if !in.running() {
			http.Error(w, errAsleep.Error(), http.StatusConflict)
			return
		}
		var out any
		var err error
		switch name {
		case "export", "import":
			// Held like a backup, so an idle sleep waits for it.
			backupMu.Lock()
			out, err = transfer(e, name, body)
			backupMu.Unlock()
		default:
			out, err = in.action(name, body)
		}
		if errors.Is(err, errUnknownAction) {
			http.Error(w, err.Error(), http.StatusNotFound)
			return
		}
		if err != nil {
			log.Printf("action %s: %v", name, err)
			http.Error(w, err.Error(), http.StatusUnprocessableEntity)
			return
		}
		writeJSON(w, out)
	})
}

var errUnknownAction = errors.New("unknown action")

// consoleRows caps what one console query returns.
const consoleRows = 200

type consoleResult struct {
	Columns   []string `json:"columns"`
	Rows      [][]any  `json:"rows"`
	Truncated bool     `json:"truncated"`
	Millis    float64  `json:"ms"`
}

// consoleInput is a console request: SQL for Postgres and MySQL; a
// collection and a JSON filter for MongoDB.
type consoleInput struct {
	SQL        string `json:"sql"`
	Collection string `json:"collection"`
	Filter     string `json:"filter"`
}

func parseConsole(body []byte) (consoleInput, error) {
	var in consoleInput
	if err := json.Unmarshal(body, &in); err != nil {
		return in, fmt.Errorf("bad request: %v", err)
	}
	if len(in.SQL) > 20000 || len(in.Filter) > 20000 {
		return in, errors.New("the query is too long")
	}
	return in, nil
}

// internalPassword is a password for a login only this pod uses, derived
// from the agent token so it survives restarts without being stored.
func internalPassword(admin, purpose string) string {
	sum := sha256.Sum256([]byte(purpose + ":" + admin))
	return hex.EncodeToString(sum[:16])
}

// cell turns a driver value into something JSON shows as the database would.
func cell(v []byte, null bool) any {
	if null {
		return nil
	}
	s := string(v)
	if len(s) > 2000 {
		s = s[:2000] + "…"
	}
	return s
}
