package main

import (
	"bufio"
	"fmt"
	"io"
	"net"
	"strconv"
	"strings"
	"time"
)

// conn is a tiny RESP client: enough for AUTH, PING and SYNC.
type conn struct {
	net.Conn
	r *bufio.Reader
}

func dialAuthed(addr, password string) (*conn, error) {
	c, err := net.DialTimeout("tcp", addr, 2*time.Second)
	if err != nil {
		return nil, err
	}
	rc := &conn{Conn: c, r: bufio.NewReader(c)}
	_ = c.SetDeadline(time.Now().Add(5 * time.Second))
	reply, err := command(rc, "AUTH", password)
	if err != nil {
		c.Close()
		return nil, err
	}
	if reply != "+OK" {
		c.Close()
		return nil, fmt.Errorf("auth: %s", reply)
	}
	return rc, nil
}

func encode(args ...string) []byte {
	var b strings.Builder
	fmt.Fprintf(&b, "*%d\r\n", len(args))
	for _, a := range args {
		fmt.Fprintf(&b, "$%d\r\n%s\r\n", len(a), a)
	}
	return []byte(b.String())
}

// command sends one command and returns a simple-string or error reply line.
func command(c *conn, args ...string) (string, error) {
	if _, err := c.Write(encode(args...)); err != nil {
		return "", err
	}
	line, err := c.r.ReadString('\n')
	return strings.TrimRight(line, "\r\n"), err
}

// pullRDB asks the server for a full resync and returns the RDB it streams.
// The server sends newlines as keepalives while it forks, then $<len>\r\n<rdb>.
func pullRDB(addr, password string) ([]byte, error) {
	c, err := dialAuthed(addr, password)
	if err != nil {
		return nil, err
	}
	defer c.Close()
	_ = c.SetDeadline(time.Now().Add(5 * time.Minute))
	if _, err := c.Write(encode("SYNC")); err != nil {
		return nil, err
	}
	for {
		line, err := c.r.ReadString('\n')
		if err != nil {
			return nil, err
		}
		line = strings.TrimRight(line, "\r\n")
		if line == "" {
			continue
		}
		if strings.HasPrefix(line, "-") {
			return nil, fmt.Errorf("sync: %s", line)
		}
		if !strings.HasPrefix(line, "$") || strings.HasPrefix(line, "$EOF:") {
			return nil, fmt.Errorf("sync: unexpected reply %q", line)
		}
		size, err := strconv.Atoi(line[1:])
		if err != nil || size < 0 {
			return nil, fmt.Errorf("sync: bad length %q", line)
		}
		rdb := make([]byte, size)
		if _, err := io.ReadFull(c.r, rdb); err != nil {
			return nil, err
		}
		if !strings.HasPrefix(string(rdb[:min(5, len(rdb))]), "REDIS") && !strings.HasPrefix(string(rdb[:min(6, len(rdb))]), "VALKEY") {
			return nil, fmt.Errorf("sync: payload is not an RDB file")
		}
		return rdb, nil
	}
}
