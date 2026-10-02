package main

// Redis over HTTP: plain fetch() and the common Redis REST clients work
// against a dply Valkey tenant from anywhere, including Workers and serverless
// functions that cannot hold a TCP connection.
//
//   https://{id}.{domain}{REST_ADDR}   Authorization: Bearer {tenant password}
//
//   POST /                 ["SET","k","v","EX",100]        one command
//   GET|POST /set/k/v      path style; a POST body is the last argument,
//                          query parameters follow it (?EX=100)
//   POST /pipeline         [["SET","k","v"],["GET","k"]]   not atomic
//   POST /multi-exec       same body, run in MULTI/EXEC
//
// Replies are {"result": …} or {"error": "…"} (HTTP 400; 401 for a bad
// token). With "Dply-Encoding: base64" (or Upstash-Encoding) bulk strings come back base64
// encoded; status replies such as OK do not.
//
// Lua runs as on TCP: EVAL, EVALSHA, SCRIPT LOAD|EXISTS and FCALL pass through.
// A script cache does not survive the tenant sleeping (only keys are saved),
// so an EVALSHA after a wake gets NOSCRIPT; REST clients then send EVAL.
//
// Every command is counted per tenant (commandCounter) for per-request billing.

import (
	"bytes"
	"context"
	"crypto/sha1"
	"crypto/subtle"
	"crypto/tls"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"
)

const (
	restMaxBody       = 10 << 20 // 10 MB request body
	restMaxCommands   = 1000     // per pipeline or transaction
	restTimeout       = 30 * time.Second
	restIdlePerTenant = 8
)

// restRefused are commands HTTP cannot serve: they block, stream, or change
// the connection's state (which would leak into the next request on a pooled
// connection). Connection commands other than PING and ECHO are refused.
// MULTI/EXEC go through /multi-exec instead.
var restRefused = map[string]string{
	"BLPOP": "blocking", "BRPOP": "blocking", "BRPOPLPUSH": "blocking", "BLMOVE": "blocking", "BLMPOP": "blocking",
	"BZPOPMIN": "blocking", "BZPOPMAX": "blocking", "BZMPOP": "blocking", "WAIT": "blocking", "WAITAOF": "blocking",
	"SUBSCRIBE": "streaming", "PSUBSCRIBE": "streaming", "SSUBSCRIBE": "streaming", "UNSUBSCRIBE": "streaming",
	"PUNSUBSCRIBE": "streaming", "SUNSUBSCRIBE": "streaming", "MONITOR": "streaming",
	"MULTI": "transaction", "EXEC": "transaction", "DISCARD": "transaction", "WATCH": "transaction", "UNWATCH": "transaction",
	"AUTH": "connection", "HELLO": "connection", "SELECT": "connection", "QUIT": "connection", "RESET": "connection",
	"CLIENT": "connection", "READONLY": "connection", "READWRITE": "connection", "SWAPDB": "connection",
}

// restCommandError is a request the gateway refuses before it reaches Valkey.
type restCommandError string

func (e restCommandError) Error() string { return string(e) }

// checkRESTCommand refuses what HTTP cannot run; XREAD / XREADGROUP only with BLOCK.
func checkRESTCommand(cmd []string) error {
	if len(cmd) == 0 || cmd[0] == "" {
		return restCommandError("ERR empty command")
	}
	name := strings.ToUpper(cmd[0])
	if why, ok := restRefused[name]; ok {
		return restCommandError(fmt.Sprintf("ERR %s is not supported over REST (%s command)", name, why))
	}
	if name == "XREAD" || name == "XREADGROUP" {
		for _, a := range cmd[1:] {
			if strings.EqualFold(a, "BLOCK") {
				return restCommandError("ERR " + name + " with BLOCK is not supported over REST (blocking command)")
			}
		}
	}
	return nil
}

// ---- Lua compatibility ----

// valkeyScriptFlags are the shebang flags Valkey understands. Some REST
// clients start their scripts with "#!lua flags=allow-key-locking", which Valkey
// rejects; unknown flags are dropped before the script runs.
var valkeyScriptFlags = map[string]bool{"no-writes": true, "allow-oom": true, "allow-stale": true, "no-cluster": true, "allow-cross-slot-keys": true}

// scriptSHAs maps the SHA a client computed over its original script to the
// SHA of the rewritten one Valkey caches, so EVALSHA and SCRIPT EXISTS with
// the client's SHA find it. Only script hashes are kept; they hold no data.
var scriptSHAs sync.Map // original sha1 -> rewritten sha1

func sha1Hex(s string) string {
	sum := sha1.Sum([]byte(s))
	return hex.EncodeToString(sum[:])
}

// rewriteScript drops flags Valkey does not know. ok is false when nothing changed.
func rewriteScript(script string) (string, bool) {
	if !strings.HasPrefix(script, "#!lua") {
		return script, false
	}
	nl := strings.IndexByte(script, '\n')
	if nl < 0 {
		nl = len(script)
	}
	fields := strings.Fields(script[:nl])
	changed := false
	for i, f := range fields {
		if !strings.HasPrefix(f, "flags=") {
			continue
		}
		var keep []string
		for _, flag := range strings.Split(strings.TrimPrefix(f, "flags="), ",") {
			if valkeyScriptFlags[flag] {
				keep = append(keep, flag)
			} else {
				changed = true
			}
		}
		fields[i] = "flags=" + strings.Join(keep, ",")
	}
	if !changed {
		return script, false
	}
	return strings.Join(fields, " ") + script[nl:], true
}

// prepareScripts rewrites scripts and script SHAs in a command. For SCRIPT
// LOAD of a rewritten script it returns the client's own SHA, to reply with.
func prepareScripts(cmd []string) (out []string, loadSHA string) {
	if len(cmd) < 2 {
		return cmd, ""
	}
	out = append([]string(nil), cmd...)
	name := strings.ToUpper(cmd[0])
	switch {
	case name == "EVAL" || name == "EVAL_RO":
		if s, ok := rewriteScript(cmd[1]); ok {
			scriptSHAs.Store(sha1Hex(cmd[1]), sha1Hex(s))
			out[1] = s
		}
	case name == "EVALSHA" || name == "EVALSHA_RO":
		if s, ok := scriptSHAs.Load(strings.ToLower(cmd[1])); ok {
			out[1] = s.(string)
		}
	case name == "SCRIPT" && len(cmd) >= 3 && strings.EqualFold(cmd[1], "LOAD"):
		if s, ok := rewriteScript(cmd[2]); ok {
			orig := sha1Hex(cmd[2])
			scriptSHAs.Store(orig, sha1Hex(s))
			out[2] = s
			return out, orig
		}
	case name == "SCRIPT" && strings.EqualFold(cmd[1], "EXISTS"):
		for i := 2; i < len(out); i++ {
			if s, ok := scriptSHAs.Load(strings.ToLower(out[i])); ok {
				out[i] = s.(string)
			}
		}
	}
	return out, ""
}

// parseRESTRequest turns an HTTP request into commands. transaction is set for /multi-exec.
func parseRESTRequest(r *http.Request, body []byte) (cmds [][]string, transaction bool, err error) {
	path := strings.Trim(r.URL.EscapedPath(), "/")
	switch {
	case path == "" && r.Method == http.MethodPost:
		var cmd []any
		if err := decodeJSON(body, &cmd); err != nil {
			return nil, false, restCommandError("ERR the body must be a JSON array, like [\"SET\",\"key\",\"value\"]")
		}
		one, err := stringArgs(cmd)
		return [][]string{one}, false, err
	case path == "pipeline" || path == "multi-exec":
		if r.Method != http.MethodPost {
			return nil, false, restCommandError("ERR use POST")
		}
		var raw [][]any
		if err := decodeJSON(body, &raw); err != nil {
			return nil, false, restCommandError("ERR the body must be a JSON array of commands, like [[\"SET\",\"k\",\"v\"],[\"GET\",\"k\"]]")
		}
		if len(raw) == 0 || len(raw) > restMaxCommands {
			return nil, false, restCommandError(fmt.Sprintf("ERR send 1 to %d commands", restMaxCommands))
		}
		for _, c := range raw {
			one, err := stringArgs(c)
			if err != nil {
				return nil, false, err
			}
			cmds = append(cmds, one)
		}
		return cmds, path == "multi-exec", nil
	case path == "":
		return nil, false, restCommandError("ERR name a command, like GET /get/key")
	}
	// Path style: /set/key/value, then a POST body, then ?name=value pairs.
	var cmd []string
	for _, seg := range strings.Split(path, "/") {
		s, err := url.PathUnescape(seg)
		if err != nil {
			return nil, false, restCommandError("ERR bad path")
		}
		cmd = append(cmd, s)
	}
	if r.Method == http.MethodPost && len(body) > 0 {
		cmd = append(cmd, string(body))
	}
	for key, values := range r.URL.Query() {
		if key == "_token" {
			continue
		}
		for _, v := range values {
			cmd = append(cmd, key)
			if v != "" {
				cmd = append(cmd, v)
			}
		}
	}
	return [][]string{cmd}, false, nil
}

func decodeJSON(body []byte, v any) error {
	d := json.NewDecoder(bytes.NewReader(body))
	d.UseNumber()
	return d.Decode(v)
}

// stringArgs accepts strings, numbers and booleans.
func stringArgs(in []any) ([]string, error) {
	if len(in) == 0 {
		return nil, restCommandError("ERR empty command")
	}
	out := make([]string, len(in))
	for i, a := range in {
		switch v := a.(type) {
		case string:
			out[i] = v
		case json.Number:
			out[i] = v.String()
		case bool:
			out[i] = strconv.FormatBool(v)
		default:
			return nil, restCommandError("ERR arguments must be strings or numbers")
		}
	}
	return out, nil
}

// restValue converts a RESP reply to JSON: bulk strings optionally base64,
// status replies as is, nested arrays recursively. Errors are returned apart.
func restValue(reply any, b64 bool) (any, error) {
	switch v := reply.(type) {
	case respError:
		return nil, v
	case []byte:
		if b64 {
			return base64.StdEncoding.EncodeToString(v), nil
		}
		return string(v), nil
	case []any:
		out := make([]any, len(v))
		for i, item := range v {
			// An error inside an array (a Lua table, EXEC) is kept as its message.
			if e, ok := item.(respError); ok {
				out[i] = string(e)
				continue
			}
			out[i], _ = restValue(item, b64)
		}
		return out, nil
	}
	return reply, nil // string (status), int64, nil
}

func restItem(reply any, b64 bool) map[string]any {
	v, err := restValue(reply, b64)
	if err != nil {
		return map[string]any{"error": err.Error()}
	}
	return map[string]any{"result": v}
}

// runREST sends the commands on c and reads their replies: one item per
// command, or for a transaction one item per queued command (or one error
// when EXEC aborts).
func runREST(c *conn, cmds [][]string, transaction bool, b64 bool) (items []map[string]any, aborted bool, err error) {
	// Lua compatibility: scripts and SHAs rewritten, SCRIPT LOAD answered with the client's SHA.
	prepared := make([][]string, len(cmds))
	loadSHAs := make([]string, len(cmds))
	for i, cmd := range cmds {
		prepared[i], loadSHAs[i] = prepareScripts(cmd)
	}
	answer := func(i int, reply any) any {
		if loadSHAs[i] != "" {
			if _, ok := reply.([]byte); ok {
				return []byte(loadSHAs[i])
			}
		}
		return reply
	}
	send := prepared
	if transaction {
		send = append(append([][]string{{"MULTI"}}, prepared...), []string{"EXEC"})
	}
	for _, cmd := range send {
		if err := c.send(strs(cmd...)...); err != nil {
			return nil, false, err
		}
	}
	if err := c.w.Flush(); err != nil {
		return nil, false, err
	}
	replies := make([]any, len(send))
	for i := range replies {
		if replies[i], err = c.read(); err != nil {
			return nil, false, err
		}
	}
	if !transaction {
		for i, r := range replies {
			items = append(items, restItem(answer(i, r), b64 && loadSHAs[i] == ""))
		}
		return items, false, nil
	}
	// MULTI, QUEUED…, EXEC. A command refused while queueing aborts EXEC.
	for _, r := range replies[1 : len(replies)-1] {
		if e, ok := r.(respError); ok {
			return []map[string]any{{"error": string(e)}}, true, nil
		}
	}
	exec := replies[len(replies)-1]
	results, ok := exec.([]any)
	if !ok {
		if e, isErr := exec.(respError); isErr {
			return []map[string]any{{"error": string(e)}}, true, nil
		}
		return []map[string]any{{"error": "ERR transaction discarded"}}, true, nil
	}
	for i, r := range results {
		items = append(items, restItem(answer(i, r), b64 && loadSHAs[i] == ""))
	}
	return items, false, nil
}

// ---- connections ----

// valkeyPort is where tenant pods listen (a variable so tests can point it at a local Valkey).
var valkeyPort = "6379"

// restPool keeps a few authenticated connections per tenant, keyed by pod IP
// so a connection to a pod that went to sleep is never reused.
type restPool struct {
	mu   sync.Mutex
	idle map[string][]*pooled
}

type pooled struct {
	*conn
	ip string
}

func (p *restPool) get(id, ip string) *pooled {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.idle == nil {
		return nil // nothing pooled yet (put makes the map)
	}
	list := p.idle[id]
	for len(list) > 0 {
		c := list[len(list)-1]
		list = list[:len(list)-1]
		if c.ip == ip {
			p.idle[id] = list
			return c
		}
		c.Close()
	}
	p.idle[id] = list
	return nil
}

func (p *restPool) put(id string, c *pooled) {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.idle == nil {
		p.idle = map[string][]*pooled{}
	}
	if len(p.idle[id]) >= restIdlePerTenant {
		c.Close()
		return
	}
	p.idle[id] = append(p.idle[id], c)
}

func dialTenant(ip, password string) (*pooled, error) {
	c, err := dial(net.JoinHostPort(ip, valkeyPort))
	if err != nil {
		return nil, err
	}
	_ = c.SetDeadline(time.Now().Add(5 * time.Second))
	if reply, err := c.do("AUTH", "default", password); err != nil || reply != "OK" {
		c.Close()
		return nil, fmt.Errorf("auth: %v %v", reply, err)
	}
	return &pooled{conn: c, ip: ip}, nil
}

// ---- metering ----

// commandCounter counts REST commands per tenant. Each gateway flushes its
// own counts onto the tenant Secret (annotationCommands), so replicas add up
// and a crash loses at most one flush interval (under-billing, never over).
type commandCounter struct {
	mu     sync.Mutex
	counts map[string]int64
}

const annotationCommands = "dply.dev/rest-commands"

func (c *commandCounter) add(id string, n int) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.counts == nil {
		c.counts = map[string]int64{}
	}
	c.counts[id] += int64(n)
}

func (c *commandCounter) take() map[string]int64 {
	c.mu.Lock()
	defer c.mu.Unlock()
	out := c.counts
	c.counts = nil
	return out
}

func (g *gateway) flushCommandCounts(ctx context.Context) {
	tick := time.NewTicker(30 * time.Second)
	defer tick.Stop()
	for {
		select {
		case <-ctx.Done():
			g.writeCommandCounts(context.Background())
			return
		case <-tick.C:
			g.writeCommandCounts(ctx)
		}
	}
}

func (g *gateway) writeCommandCounts(ctx context.Context) {
	for id, n := range g.commands.take() {
		g.annotate(ctx, id, func(a map[string]string) {
			total, _ := strconv.ParseInt(a[annotationCommands], 10, 64)
			a[annotationCommands] = strconv.FormatInt(total+n, 10)
		})
	}
}

// ---- HTTP ----

func (g *gateway) serveREST(certs *certReloader) {
	if g.cfg.restAddr == "" || g.cfg.restAddr == "off" {
		return
	}
	srv := &http.Server{
		Addr:              g.cfg.restAddr,
		Handler:           http.HandlerFunc(g.restHandler),
		ReadHeaderTimeout: 10 * time.Second,
		TLSConfig:         &tls.Config{GetCertificate: certs.get, MinVersion: tls.VersionTLS12},
	}
	log.Printf("rest on %s", g.cfg.restAddr)
	log.Fatal(srv.ListenAndServeTLS("", ""))
}

func (g *gateway) restHandler(w http.ResponseWriter, r *http.Request) {
	fail := func(status int, msg string) { writeJSON(w, status, map[string]any{"error": msg}) }
	if r.Method != http.MethodGet && r.Method != http.MethodPost {
		fail(http.StatusMethodNotAllowed, "ERR use GET or POST")
		return
	}
	host, _, err := net.SplitHostPort(r.Host)
	if err != nil {
		host = r.Host
	}
	id, ok := tenantFromName(strings.ToLower(host), g.cfg.domain)
	if !ok {
		fail(http.StatusNotFound, "ERR unknown database")
		return
	}
	token := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
	if token == "" {
		token = r.URL.Query().Get("_token")
	}
	ctx, cancel := context.WithTimeout(r.Context(), restTimeout)
	defer cancel()
	// Check the token before waking: a bad request must not start a pod.
	t, err := g.getTenantRecord(ctx, id)
	if err != nil || isDatabase(t.Engine) || token == "" || subtle.ConstantTimeCompare([]byte(token), []byte(t.Password)) != 1 {
		fail(http.StatusUnauthorized, "Unauthorized")
		return
	}
	body, err := io.ReadAll(io.LimitReader(r.Body, restMaxBody+1))
	if err != nil || len(body) > restMaxBody {
		fail(http.StatusRequestEntityTooLarge, "ERR the request is larger than 10 MB")
		return
	}
	cmds, transaction, err := parseRESTRequest(r, body)
	if err != nil {
		fail(http.StatusBadRequest, err.Error())
		return
	}
	for _, cmd := range cmds {
		if err := checkRESTCommand(cmd); err != nil {
			fail(http.StatusBadRequest, err.Error())
			return
		}
	}
	b64 := strings.EqualFold(r.Header.Get("Upstash-Encoding"), "base64") || strings.EqualFold(r.Header.Get("Dply-Encoding"), "base64")

	exec := g.execREST
	if t.Shared {
		exec = g.execNano
	}
	items, aborted, err := exec(ctx, *t, cmds, transaction, b64)
	if err != nil {
		log.Printf("tenant %s: rest: %v", id, err)
		fail(http.StatusServiceUnavailable, "ERR this database is not reachable")
		return
	}
	g.commands.add(id, len(cmds))
	g.touch(id)

	if len(cmds) == 1 && !transaction && strings.Trim(r.URL.Path, "/") != "pipeline" {
		status := http.StatusOK
		if _, isErr := items[0]["error"]; isErr {
			status = http.StatusBadRequest
		}
		writeJSON(w, status, items[0])
		return
	}
	if aborted {
		writeJSON(w, http.StatusBadRequest, items[0])
		return
	}
	writeJSON(w, http.StatusOK, items)
}

// execREST wakes the tenant and runs the commands on a pooled connection.
// Only a reused connection that turns out dead is retried (on a fresh one):
// a fresh connection that fails mid-request may have run the commands
// already, and retrying could run INCR twice.
func (g *gateway) execREST(ctx context.Context, t tenant, cmds [][]string, transaction, b64 bool) ([]map[string]any, bool, error) {
	var last error
	for attempt := 0; attempt < 2; attempt++ {
		ip, err := g.wake(ctx, t.ID)
		if err != nil {
			return nil, false, err
		}
		c := g.rest.get(t.ID, ip)
		reused := c != nil
		if !reused {
			if c, err = dialTenant(ip, t.Password); err != nil {
				g.forget(t.ID) // the pod went away; wake again
				last = err
				continue
			}
		}
		deadline, _ := ctx.Deadline()
		_ = c.SetDeadline(deadline)
		items, aborted, err := runREST(c.conn, cmds, transaction, b64)
		if err != nil {
			c.Close()
			last = err
			if reused && !errors.Is(err, context.DeadlineExceeded) {
				continue
			}
			return nil, false, err
		}
		_ = c.SetDeadline(time.Time{})
		g.rest.put(t.ID, c)
		return items, aborted, nil
	}
	return nil, false, last
}
