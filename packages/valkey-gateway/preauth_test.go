package main

import (
	"bufio"
	"context"
	"strconv"
	"strings"
	"testing"
)

func frame(args ...string) string {
	var b strings.Builder
	b.WriteString("*" + strconv.Itoa(len(args)) + "\r\n")
	for _, a := range args {
		b.WriteString("$" + strconv.Itoa(len(a)) + "\r\n" + a + "\r\n")
	}
	return b.String()
}

func TestPreauthAcceptsAuthAndHelloAndKeepsPipelinedBytes(t *testing.T) {
	cases := []struct {
		args       []string
		user, pass string
	}{
		{[]string{"AUTH", "pw-0123456789abcdef"}, "default", "pw-0123456789abcdef"},
		{[]string{"auth", "default", "pw-0123456789abcdef"}, "default", "pw-0123456789abcdef"},
		{[]string{"HELLO", "3", "AUTH", "default", "pw-0123456789abcdef", "SETNAME", "x"}, "default", "pw-0123456789abcdef"},
	}
	for _, c := range cases {
		wire := frame(c.args...)
		r := bufio.NewReader(strings.NewReader(wire + frame("PING")))
		args, raw, err := readCommand(r)
		if err != nil {
			t.Fatalf("%v: %v", c.args, err)
		}
		if string(raw) != wire {
			t.Fatalf("raw frame not preserved: %q", raw)
		}
		user, pass, ok := commandCredentials(args)
		if !ok || user != c.user || pass != c.pass || !credentialsMatch(user, pass, c.pass) {
			t.Fatalf("%v: got %q %q %v", c.args, user, pass, ok)
		}
		if rest, _ := r.Peek(r.Buffered()); string(rest) != frame("PING") {
			t.Fatalf("pipelined command lost: %q", rest)
		}
	}
}

func TestPreauthRejectsAnythingThatCannotWake(t *testing.T) {
	for _, args := range [][]string{{"PING"}, {"HELLO", "3"}, {"GET", "k"}} {
		parsed, _, err := readCommand(bufio.NewReader(strings.NewReader(frame(args...))))
		if err != nil {
			t.Fatal(err)
		}
		if _, _, ok := commandCredentials(parsed); ok {
			t.Fatalf("%v must not count as AUTH", args)
		}
	}
	if credentialsMatch("default", "wrong", "right-password") || credentialsMatch("admin", "p", "p") || credentialsMatch("default", "", "") {
		t.Fatal("bad credentials matched")
	}
	for _, wire := range []string{"PING\r\n", "*1\r\n$99999\r\n", "*100\r\n", "*1\r\n$4\r\nAUTHxx"} {
		if _, _, err := readCommand(bufio.NewReader(strings.NewReader(wire))); err == nil {
			t.Fatalf("%q parsed", wire)
		}
	}
}

func TestHandshakeOnlyPassesTheTenantsOwnLogin(t *testing.T) {
	const pw = "tenant-password-0123"
	lookups := 0
	lookup := func(_ context.Context, id string) (*tenant, error) {
		lookups++
		switch id {
		case "kv1":
			return &tenant{ID: id, Password: pw}, nil
		case "pg1":
			return &tenant{ID: id, Password: pw, Engine: "postgres"}, nil
		}
		return nil, errNotFound
	}
	gate := func(id, wire string) ([]byte, string) {
		return authorizeFirstCommand(bufio.NewReader(strings.NewReader(wire)), id, lookup)
	}

	if _, reply := gate("kv1", frame("PING")); !strings.HasPrefix(reply, "-NOAUTH") || lookups != 0 {
		t.Fatalf("PING first: %q (lookups %d)", reply, lookups)
	}
	for _, c := range []struct{ id, wire string }{
		{"kv1", frame("AUTH", "wrong")},
		{"kv1", frame("AUTH", "admin", pw)},
		{"pg1", frame("AUTH", pw)},
		{"nope", frame("AUTH", pw)},
	} {
		if first, reply := gate(c.id, c.wire); first != nil || !strings.HasPrefix(reply, "-WRONGPASS") {
			t.Fatalf("%s %q: got %q %q", c.id, c.wire, first, reply)
		}
	}
	login := frame("AUTH", "default", pw)
	if first, reply := gate("kv1", login); reply != "" || string(first) != login {
		t.Fatalf("good login: %q %q", first, reply)
	}
}
