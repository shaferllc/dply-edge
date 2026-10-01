package main

import (
	"encoding/base64"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"strconv"
	"strings"
	"testing"
)

func TestParseRESTRequest(t *testing.T) {
	cases := []struct {
		method, target, body string
		want                 [][]string
		tx                   bool
	}{
		{"POST", "/", `["SET","k","v","EX",100]`, [][]string{{"SET", "k", "v", "EX", "100"}}, false},
		{"GET", "/set/k/v", "", [][]string{{"set", "k", "v"}}, false},
		{"GET", "/get/a%2Fb", "", [][]string{{"get", "a/b"}}, false},
		{"POST", "/set/k?EX=100", "hello world", [][]string{{"set", "k", "hello world", "EX", "100"}}, false},
		{"GET", "/get/k?_token=secret", "", [][]string{{"get", "k"}}, false},
		{"POST", "/pipeline", `[["SET","a",1],["INCR","a"]]`, [][]string{{"SET", "a", "1"}, {"INCR", "a"}}, false},
		{"POST", "/multi-exec", `[["SET","a",true]]`, [][]string{{"SET", "a", "true"}}, true},
	}
	for _, c := range cases {
		r := httptest.NewRequest(c.method, c.target, strings.NewReader(c.body))
		got, tx, err := parseRESTRequest(r, []byte(c.body))
		if err != nil || tx != c.tx || !reflect.DeepEqual(got, c.want) {
			t.Errorf("%s %s: got %v tx=%v err=%v, want %v", c.method, c.target, got, tx, err, c.want)
		}
	}
	for _, bad := range []struct{ method, target, body string }{
		{"POST", "/", `{"not":"an array"}`},
		{"POST", "/", `[]`},
		{"POST", "/pipeline", `[]`},
		{"GET", "/pipeline", ``},
		{"POST", "/", `["SET",{"x":1}]`},
	} {
		r := httptest.NewRequest(bad.method, bad.target, strings.NewReader(bad.body))
		if _, _, err := parseRESTRequest(r, []byte(bad.body)); err == nil {
			t.Errorf("%s %s %s: want an error", bad.method, bad.target, bad.body)
		}
	}
}

func TestCheckRESTCommand(t *testing.T) {
	for _, ok := range [][]string{{"GET", "k"}, {"eval", "return 1", "0"}, {"EVALSHA", "abc", "0"}, {"SCRIPT", "LOAD", "return 1"}, {"XREAD", "STREAMS", "s", "0"}, {"PING"}} {
		if err := checkRESTCommand(ok); err != nil {
			t.Errorf("%v: %v", ok, err)
		}
	}
	for _, bad := range [][]string{{"BLPOP", "k", "0"}, {"subscribe", "c"}, {"MULTI"}, {"SELECT", "1"}, {"CLIENT", "SETNAME", "x"}, {"XREAD", "BLOCK", "0", "STREAMS", "s", "$"}, {""}} {
		if err := checkRESTCommand(bad); err == nil {
			t.Errorf("%v: want refused", bad)
		}
	}
}

func TestRESTValueEncoding(t *testing.T) {
	v, _ := restValue([]byte("bar"), true)
	if v != base64.StdEncoding.EncodeToString([]byte("bar")) {
		t.Errorf("bulk not base64: %v", v)
	}
	if v, _ := restValue("OK", true); v != "OK" {
		t.Errorf("status encoded: %v", v)
	}
	if v, _ := restValue(int64(7), true); v != int64(7) {
		t.Errorf("int: %v", v)
	}
	if v, _ := restValue(nil, true); v != nil {
		t.Errorf("nil: %v", v)
	}
	nested, _ := restValue([]any{[]byte("a"), int64(1), nil, []any{[]byte("b")}}, false)
	if !reflect.DeepEqual(nested, []any{"a", int64(1), nil, []any{"b"}}) {
		t.Errorf("nested: %v", nested)
	}
	if _, err := restValue(respError("ERR x"), false); err == nil || err.Error() != "ERR x" {
		t.Errorf("error: %v", err)
	}
}

// Against a real Valkey: VALKEY_TEST_ADDR=127.0.0.1:16379 VALKEY_TEST_PASSWORD=… go test -run Live
func TestRunRESTLive(t *testing.T) {
	addr := os.Getenv("VALKEY_TEST_ADDR")
	if addr == "" {
		t.Skip("VALKEY_TEST_ADDR not set")
	}
	c, err := dial(addr)
	if err != nil {
		t.Fatal(err)
	}
	defer c.Close()
	if reply, err := c.do("AUTH", os.Getenv("VALKEY_TEST_PASSWORD")); err != nil || reply != "OK" {
		t.Fatalf("auth: %v %v", reply, err)
	}
	c.do("DEL", "rt:k", "rt:n", "rt:s")

	run := func(cmds [][]string, tx, b64 bool) ([]map[string]any, bool) {
		t.Helper()
		items, aborted, err := runREST(c, cmds, tx, b64)
		if err != nil {
			t.Fatal(err)
		}
		return items, aborted
	}

	items, _ := run([][]string{{"SET", "rt:k", "bar"}, {"GET", "rt:k"}, {"INCR", "rt:n"}, {"GET", "rt:missing"}, {"LPUSH", "rt:k", "x"}}, false, false)
	want := []map[string]any{{"result": "OK"}, {"result": "bar"}, {"result": int64(1)}, {"result": nil}}
	if !reflect.DeepEqual(items[:4], want) {
		t.Errorf("pipeline: %v", items)
	}
	if _, isErr := items[4]["error"]; !isErr {
		t.Errorf("WRONGTYPE should be an error item: %v", items[4])
	}

	if items, _ := run([][]string{{"GET", "rt:k"}}, false, true); items[0]["result"] != base64.StdEncoding.EncodeToString([]byte("bar")) {
		t.Errorf("base64: %v", items)
	}

	// Lua: EVAL, SCRIPT LOAD + EVALSHA, a table reply, and NOSCRIPT for an unknown sha.
	script := "redis.call('INCRBY', KEYS[1], ARGV[1]); return {redis.call('GET', KEYS[1]), 'done'}"
	items, _ = run([][]string{{"EVAL", script, "1", "rt:n", "5"}}, false, false)
	if !reflect.DeepEqual(items[0]["result"], []any{"6", "done"}) {
		t.Errorf("eval: %v", items)
	}
	items, _ = run([][]string{{"SCRIPT", "LOAD", script}}, false, false)
	sha, _ := items[0]["result"].(string)
	items, _ = run([][]string{{"EVALSHA", sha, "1", "rt:n", "1"}, {"EVALSHA", strings.Repeat("0", 40), "0"}}, false, false)
	if !reflect.DeepEqual(items[0]["result"], []any{"7", "done"}) {
		t.Errorf("evalsha: %v", items)
	}
	if e, _ := items[1]["error"].(string); !strings.HasPrefix(e, "NOSCRIPT") {
		t.Errorf("unknown sha should be NOSCRIPT: %v", items[1])
	}

	// Transactions: results per command; a runtime error stays an item; a queueing error aborts.
	items, aborted := run([][]string{{"SET", "rt:s", "1"}, {"INCR", "rt:s"}, {"LPUSH", "rt:s", "x"}}, true, false)
	if aborted || items[0]["result"] != "OK" || items[1]["result"] != int64(2) {
		t.Errorf("multi-exec: %v aborted=%v", items, aborted)
	}
	if _, isErr := items[2]["error"]; !isErr && items[2]["result"] == nil {
		t.Errorf("runtime error inside EXEC should surface: %v", items[2])
	}
	items, aborted = run([][]string{{"SET", "rt:s", "1"}, {"NOSUCHCOMMAND"}}, true, false)
	if !aborted || len(items) != 1 {
		t.Errorf("queueing error should abort: %v aborted=%v", items, aborted)
	}
	// The connection is still usable afterwards (no MULTI left open).
	if items, _ := run([][]string{{"PING"}}, false, false); items[0]["result"] != "PONG" {
		t.Errorf("connection state after abort: %v", items)
	}
	c.do("DEL", "rt:k", "rt:n", "rt:s")
}

// The published @upstash/redis and @upstash/ratelimit against the real handler:
// VALKEY_TEST_ADDR=127.0.0.1:16379 VALKEY_TEST_PASSWORD=… UPSTASH_SDK_DIR=<dir with node_modules/@upstash> go test -run SDK
func TestRESTUpstashSDKLive(t *testing.T) {
	addr, sdk := os.Getenv("VALKEY_TEST_ADDR"), os.Getenv("UPSTASH_SDK_DIR")
	if addr == "" || sdk == "" {
		t.Skip("VALKEY_TEST_ADDR and UPSTASH_SDK_DIR not set")
	}
	host, port, _ := net.SplitHostPort(addr)
	valkeyPort = port
	defer func() { valkeyPort = "6379" }()

	g := &gateway{cfg: config{domain: "localhost"}, tenants: map[string]*tenantState{}}
	g.records.put(tenant{ID: "sdk1", Password: os.Getenv("VALKEY_TEST_PASSWORD"), Engine: "valkey"})
	g.state("sdk1").ip = host // awake: wake() returns at once

	ln, err := net.Listen("tcp", "[::1]:0")
	if err != nil {
		t.Fatal(err)
	}
	srv := httptest.NewUnstartedServer(http.HandlerFunc(g.restHandler))
	srv.Listener = ln
	srv.Start()
	defer srv.Close()

	// Wrong token: 401 and the tenant is not touched.
	req := httptest.NewRequest("GET", "/get/k", nil)
	req.Host = "sdk1.localhost"
	req.Header.Set("Authorization", "Bearer wrong")
	rec := httptest.NewRecorder()
	g.restHandler(rec, req)
	if rec.Code != http.StatusUnauthorized {
		t.Errorf("bad token: %d %s", rec.Code, rec.Body.String())
	}

	// ES modules resolve packages next to the script, so run a copy inside the SDK folder.
	src, err := os.ReadFile("testdata/upstash-sdk.mjs")
	if err != nil {
		t.Fatal(err)
	}
	script := filepath.Join(sdk, "upstash-sdk-check.mjs")
	if err := os.WriteFile(script, src, 0o644); err != nil {
		t.Fatal(err)
	}
	defer os.Remove(script)
	cmd := exec.Command("node", script)
	cmd.Dir = sdk
	cmd.Env = append(os.Environ(),
		"REST_URL=http://sdk1.localhost:"+strconv.Itoa(ln.Addr().(*net.TCPAddr).Port),
		"REST_TOKEN="+os.Getenv("VALKEY_TEST_PASSWORD"),
	)
	out, err := cmd.CombinedOutput()
	if err != nil || !strings.Contains(string(out), "ok") {
		t.Fatalf("sdk: %v\n%s", err, out)
	}
	if n := g.commands.take()["sdk1"]; n < 10 {
		t.Errorf("commands counted: %d", n)
	}
}

func TestRewriteScriptFlagsAndSHAs(t *testing.T) {
	script := "#!lua flags=allow-key-locking,no-writes\nreturn 1"
	got, ok := rewriteScript(script)
	if !ok || got != "#!lua flags=no-writes\nreturn 1" {
		t.Fatalf("rewrite: %q %v", got, ok)
	}
	if _, ok := rewriteScript("return 1"); ok {
		t.Error("a script without a shebang is left alone")
	}
	if _, ok := rewriteScript("#!lua flags=no-writes\nreturn 1"); ok {
		t.Error("known flags are left alone")
	}

	// SCRIPT LOAD answers with the client's SHA; EVALSHA and SCRIPT EXISTS with it reach the rewritten script.
	orig := sha1Hex(script)
	load, loadSHA := prepareScripts([]string{"SCRIPT", "LOAD", script})
	if loadSHA != orig || load[2] != got {
		t.Errorf("load: %v %q", load, loadSHA)
	}
	if evalsha, _ := prepareScripts([]string{"EVALSHA", orig, "0"}); evalsha[1] != sha1Hex(got) {
		t.Errorf("evalsha: %v", evalsha)
	}
	if exists, _ := prepareScripts([]string{"SCRIPT", "EXISTS", orig, "abc"}); exists[2] != sha1Hex(got) || exists[3] != "abc" {
		t.Errorf("exists: %v", exists)
	}
}
