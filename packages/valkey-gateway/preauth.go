package main

import (
	"bufio"
	"bytes"
	"context"
	"crypto/subtle"
	"errors"
	"io"
	"log"
	"strconv"
	"strings"
)

// The proxy checks a tenant's password before waking it, as the MySQL path
// does: a bare TLS connection to {id}.{domain} must not start a pod (and bill
// awake seconds) for someone who cannot log in. The first command has to be
// AUTH or HELLO … AUTH — the same rule Valkey applies — and is replayed to the
// pod verbatim once it checks out.

const (
	maxPreauthArgs   = 16
	maxPreauthArgLen = 4096
)

var errBadFrame = errors.New("bad frame")

// readCommand reads one RESP array of bulk strings and returns its arguments
// plus the raw bytes read, so the frame can be forwarded unchanged.
func readCommand(r *bufio.Reader) ([][]byte, []byte, error) {
	var raw bytes.Buffer
	line := func() (string, error) {
		b, err := r.ReadSlice('\n') // bounded by the reader's buffer
		raw.Write(b)
		if err != nil {
			return "", err
		}
		s := string(b)
		if len(s) > 32 || !strings.HasSuffix(s, "\r\n") {
			return "", errBadFrame
		}
		return strings.TrimSuffix(s, "\r\n"), nil
	}
	head, err := line()
	if err != nil {
		return nil, nil, err
	}
	if len(head) < 2 || head[0] != '*' {
		return nil, nil, errBadFrame
	}
	n, err := strconv.Atoi(head[1:])
	if err != nil || n < 1 || n > maxPreauthArgs {
		return nil, nil, errBadFrame
	}
	args := make([][]byte, n)
	for i := range args {
		h, err := line()
		if err != nil {
			return nil, nil, err
		}
		if len(h) < 2 || h[0] != '$' {
			return nil, nil, errBadFrame
		}
		size, err := strconv.Atoi(h[1:])
		if err != nil || size < 0 || size > maxPreauthArgLen {
			return nil, nil, errBadFrame
		}
		b := make([]byte, size+2)
		if _, err := io.ReadFull(r, b); err != nil {
			return nil, nil, err
		}
		raw.Write(b)
		if b[size] != '\r' || b[size+1] != '\n' {
			return nil, nil, errBadFrame
		}
		args[i] = b[:size]
	}
	return args, raw.Bytes(), nil
}

// commandCredentials pulls the user and password out of AUTH [user] pass or
// HELLO ver AUTH user pass [...]. ok is false for any other command.
func commandCredentials(args [][]byte) (user, pass string, ok bool) {
	if len(args) == 0 {
		return "", "", false
	}
	switch strings.ToUpper(string(args[0])) {
	case "AUTH":
		switch len(args) {
		case 2:
			return "default", string(args[1]), true
		case 3:
			return string(args[1]), string(args[2]), true
		}
	case "HELLO":
		for i := 2; i+2 < len(args); i++ {
			if strings.EqualFold(string(args[i]), "AUTH") {
				return string(args[i+1]), string(args[i+2]), true
			}
		}
	}
	return "", "", false
}

// credentialsMatch is the tenant login check: user "default" with its password.
func credentialsMatch(user, pass, want string) bool {
	return want != "" && user == "default" && subtle.ConstantTimeCompare([]byte(pass), []byte(want)) == 1
}

// authorizeFirstCommand reads the client's first command and checks it logs in
// as tenant id. It returns the frame to replay to the pod, or the reply to send
// before hanging up. Nothing here wakes anything.
func authorizeFirstCommand(r *bufio.Reader, id string, lookup func(context.Context, string) (*tenant, error)) ([]byte, string) {
	args, first, err := readCommand(r)
	if err != nil {
		return nil, "-ERR Protocol error\r\n"
	}
	user, pass, ok := commandCredentials(args)
	if !ok {
		return nil, "-NOAUTH Authentication required.\r\n"
	}
	t, err := lookup(context.Background(), id)
	if err != nil && !errors.Is(err, errNotFound) {
		log.Printf("tenant %s: record lookup failed: %v", id, err)
		return nil, "-ERR this database could not start\r\n"
	}
	if t == nil || isDatabase(t.Engine) || !credentialsMatch(user, pass, t.Password) {
		return nil, "-WRONGPASS invalid username-password pair or user is disabled.\r\n"
	}
	return first, ""
}
