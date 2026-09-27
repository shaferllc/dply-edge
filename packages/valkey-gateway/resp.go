package main

import (
	"bufio"
	"errors"
	"fmt"
	"io"
	"net"
	"strconv"
	"strings"
	"time"
)

// conn is a small RESP client: commands, pipelining, and binary-safe replies.
type conn struct {
	net.Conn
	r *bufio.Reader
	w *bufio.Writer
}

// adminUser is the gateway's own Valkey user on every pod. Tenants log in as
// "default", which the gateway turns on with their password (applyTenant).
const adminUser = "dply-admin"

func dial(addr string) (*conn, error) {
	c, err := net.DialTimeout("tcp", addr, 2*time.Second)
	if err != nil {
		return nil, err
	}
	return &conn{Conn: c, r: bufio.NewReaderSize(c, 64*1024), w: bufio.NewWriterSize(c, 64*1024)}, nil
}

func dialAdmin(addr, adminPassword string) (*conn, error) {
	c, err := dial(addr)
	if err != nil {
		return nil, err
	}
	_ = c.SetDeadline(time.Now().Add(5 * time.Second))
	if reply, err := c.do("AUTH", adminUser, adminPassword); err != nil || reply != "OK" {
		c.Close()
		return nil, fmt.Errorf("admin auth: %v %v", reply, err)
	}
	return c, nil
}

func (c *conn) send(args ...[]byte) error {
	fmt.Fprintf(c.w, "*%d\r\n", len(args))
	for _, a := range args {
		fmt.Fprintf(c.w, "$%d\r\n", len(a))
		c.w.Write(a)
		c.w.WriteString("\r\n")
	}
	return nil
}

func strs(args ...string) [][]byte {
	out := make([][]byte, len(args))
	for i, a := range args {
		out[i] = []byte(a)
	}
	return out
}

// do sends one command and reads one reply.
func (c *conn) do(args ...string) (any, error) {
	if err := c.send(strs(args...)...); err != nil {
		return nil, err
	}
	if err := c.w.Flush(); err != nil {
		return nil, err
	}
	return c.read()
}

type respError string

func (e respError) Error() string { return string(e) }

// read returns string (simple), []byte (bulk), nil (null), int64, []any, or a respError value.
func (c *conn) read() (any, error) {
	line, err := c.r.ReadString('\n')
	if err != nil {
		return nil, err
	}
	line = strings.TrimSuffix(line, "\r\n")
	if line == "" {
		return nil, errors.New("empty reply")
	}
	switch line[0] {
	case '+':
		return line[1:], nil
	case '-':
		return respError(line[1:]), nil
	case ':':
		return strconv.ParseInt(line[1:], 10, 64)
	case '$':
		n, err := strconv.Atoi(line[1:])
		if err != nil || n < 0 {
			return nil, err
		}
		b := make([]byte, n+2)
		if _, err := io.ReadFull(c.r, b); err != nil {
			return nil, err
		}
		return b[:n], nil
	case '*':
		n, err := strconv.Atoi(line[1:])
		if err != nil || n < 0 {
			return nil, err
		}
		out := make([]any, n)
		for i := range out {
			if out[i], err = c.read(); err != nil {
				return nil, err
			}
		}
		return out, nil
	}
	return nil, fmt.Errorf("unexpected reply %q", line)
}

// applyTenant turns on the tenant's login and sets its memory cap. Safe to
// repeat: a restarted container comes back with "default" off.
func applyTenant(c *conn, t tenant) error {
	commands := [][]string{
		{"ACL", "SETUSER", "default", "reset", "on", ">" + t.Password, "~*", "&*", "+@all",
			"-config", "-debug", "-module", "-acl", "-replicaof", "-slaveof", "-shutdown",
			"-save", "-bgsave", "-bgrewriteaof", "-sync", "-psync", "-failover", "-monitor",
			"-cluster", "-client|kill", "-migrate"},
		{"CONFIG", "SET", "maxmemory", strconv.Itoa(t.MemoryMB) + "mb"},
		// Also here, not only at pod start: a pool pod made by an older
		// gateway still has the old policy when a tenant adopts it.
		{"CONFIG", "SET", "maxmemory-policy", "volatile-lru"},
	}
	for _, cmd := range commands {
		reply, err := c.do(cmd...)
		if err != nil {
			return err
		}
		if e, ok := reply.(respError); ok {
			return fmt.Errorf("%s: %s", cmd[0], e)
		}
	}
	return nil
}

// waitReady polls until the server has loaded and answers the admin user.
func waitReady(ip, adminPassword string, within time.Duration) error {
	deadline := time.Now().Add(within)
	var last error
	for time.Now().Before(deadline) {
		c, err := dialAdmin(net.JoinHostPort(ip, "6379"), adminPassword)
		if err == nil {
			reply, err := c.do("PING")
			c.Close()
			if err == nil && reply == "PONG" {
				return nil
			}
			last = fmt.Errorf("ping: %v %v", reply, err)
		} else {
			last = err
		}
		time.Sleep(25 * time.Millisecond)
	}
	return fmt.Errorf("valkey did not answer: %v", last)
}
