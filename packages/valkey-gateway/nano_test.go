package main

import (
	"os"
	"reflect"
	"testing"
)

func TestNanoRewritePrefixesEveryKey(t *testing.T) {
	cases := []struct{ in, want []string }{
		{[]string{"SET", "a", "1", "EX", "60"}, []string{"SET", "n:t1:a", "1", "EX", "60"}},
		{[]string{"MGET", "a", "b"}, []string{"MGET", "n:t1:a", "n:t1:b"}},
		{[]string{"MSET", "a", "1", "b", "2"}, []string{"MSET", "n:t1:a", "1", "n:t1:b", "2"}},
		{[]string{"rename", "a", "b"}, []string{"rename", "n:t1:a", "n:t1:b"}},
		{[]string{"HSET", "h", "f", "v"}, []string{"HSET", "n:t1:h", "f", "v"}},
		{[]string{"PING"}, []string{"PING"}},
	}
	for _, c := range cases {
		got, err := nanoRewrite(c.in, nanoPrefix("t1"), false)
		if err != nil || !reflect.DeepEqual(got, c.want) {
			t.Fatalf("%v: got %v, %v", c.in, got, err)
		}
	}
}

func TestNanoRefusesWhatCouldReachOtherTenants(t *testing.T) {
	for _, cmd := range [][]string{{"KEYS", "*"}, {"SCAN", "0"}, {"FLUSHALL"}, {"FLUSHDB"}, {"EVAL", "return 1", "0"}, {"INFO"}, {"DBSIZE"}, {"PUBLISH", "c", "m"}, {"RANDOMKEY"}, {"ZUNIONSTORE", "d", "2", "a", "b"}, {"OBJECT", "ENCODING", "a"}, {"COPY", "a", "b", "DB", "1"}} {
		if _, err := nanoRewrite(cmd, nanoPrefix("t1"), false); err == nil {
			t.Fatalf("%v was allowed", cmd)
		}
	}
	if _, err := nanoRewrite([]string{"GET"}, nanoPrefix("t1"), false); err == nil {
		t.Fatal("GET with no key was allowed")
	}
}

func TestNanoOverQuotaRefusesGrowthOnly(t *testing.T) {
	if _, err := nanoRewrite([]string{"SET", "a", "1"}, nanoPrefix("t1"), true); err == nil {
		t.Fatal("SET while over quota was allowed")
	}
	for _, cmd := range [][]string{{"GET", "a"}, {"DEL", "a"}, {"EXPIRE", "a", "10"}} {
		if _, err := nanoRewrite(cmd, nanoPrefix("t1"), true); err != nil {
			t.Fatalf("%v refused while over quota: %v", cmd, err)
		}
	}
}

func TestRespString(t *testing.T) {
	if respString([]byte("k")) != "k" || respString("0") != "0" || respString(int64(5)) != "5" {
		t.Fatal("respString")
	}
}

// Against a real Valkey: NANO_VALKEY_ADDR=127.0.0.1:6379 go test -run Valkey
func TestNanoAgainstValkey(t *testing.T) {
	addr := os.Getenv("NANO_VALKEY_ADDR")
	if addr == "" {
		t.Skip("set NANO_VALKEY_ADDR")
	}
	c, err := dial(addr)
	if err != nil {
		t.Fatal(err)
	}
	defer c.Close()
	run := func(id string, cmd ...string) map[string]any {
		out, err := nanoRewrite(cmd, nanoPrefix(id), false)
		if err != nil {
			t.Fatal(err)
		}
		items, _, err := runREST(c, [][]string{out}, false, false)
		if err != nil {
			t.Fatal(err)
		}
		return items[0]
	}
	run("alpha", "SET", "k", "secret")
	if got := run("beta", "GET", "k")["result"]; got != nil {
		t.Fatalf("beta read alpha's key: %v", got)
	}
	if got := run("alpha", "GET", "k")["result"]; got != "secret" {
		t.Fatalf("alpha's own key: %v", got)
	}
	bytes, keys, err := nanoUsage(c, nanoPrefix("alpha"))
	if err != nil || keys != 1 || bytes <= 0 {
		t.Fatalf("usage: %d bytes, %d keys, %v", bytes, keys, err)
	}
}

func TestNanoPoolNeverEvicts(t *testing.T) {
	if evictionPolicy(tenant{ID: nanoPoolID}) != "noeviction" || evictionPolicy(tenant{ID: "abc"}) != "volatile-lru" {
		t.Fatal("eviction policy")
	}
}
