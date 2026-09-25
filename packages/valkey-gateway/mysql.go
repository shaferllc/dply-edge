package main

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/sha1"
	"crypto/subtle"
	"crypto/tls"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"time"
)

// MySQL (ruling r-67chv2jdx2ha025q): the server speaks first, so the gateway
// cannot learn the tenant (SNI) before the client must answer a greeting, and
// the client's password proof is bound to that greeting's scramble. The
// gateway therefore terminates auth: it greets, requires TLS, reads SNI,
// checks the password itself, logs into the tenant's mysqld as "app" with the
// client's own capabilities, relays mysqld's OK, and pipes the command phase.
//
// Client side: caching_sha2_password (MySQL 8's default) with full
// authentication over TLS (the client sends the password inside TLS), or
// mysql_native_password against our scramble. Backend side (pod network
// only): mysql_native_password, enabled in the dply MySQL image.

const (
	myClientConnectWithDB     = 0x00000008
	myClientCompress          = 0x00000020
	myClientProtocol41        = 0x00000200
	myClientSSL               = 0x00000800
	myClientSecureConnection  = 0x00008000
	myClientPluginAuth        = 0x00080000
	myClientConnectAttrs      = 0x00100000
	myClientPluginAuthLenenc  = 0x00200000
	myClientZstdCompression   = 0x04000000
	myServerCapabilities      = 0xDFFFFFFF &^ (myClientCompress | myClientZstdCompression)
	myDefaultCharset          = 255 // utf8mb4_0900_ai_ci
	mySHA2Plugin              = "caching_sha2_password"
	myNativePlugin            = "mysql_native_password"
	myErrAccessDenied         = 1045
	myErrHandshake            = 1043
	myErrServerUnavailable    = 2002
	myPerformFullAuth         = 0x04
	myAuthMoreData            = 0x01
	myAuthSwitch              = 0xFE
	myOK                      = 0x00
	myErr                     = 0xFF
	myMaxHandshakePacket      = 64 << 10
	myHandshakeTimeout        = 15 * time.Second
	myServerVersion           = "8.4.0-dply"
	myScrambleLength          = 20
	myBackendHandshakeTimeout = 10 * time.Second
)

// ---- packets ----

func myRead(r io.Reader) (seq byte, payload []byte, err error) {
	var head [4]byte
	if _, err = io.ReadFull(r, head[:]); err != nil {
		return 0, nil, err
	}
	n := int(head[0]) | int(head[1])<<8 | int(head[2])<<16
	if n > myMaxHandshakePacket {
		return 0, nil, fmt.Errorf("mysql packet of %d bytes during handshake", n)
	}
	payload = make([]byte, n)
	_, err = io.ReadFull(r, payload)
	return head[3], payload, err
}

func myWrite(w io.Writer, seq byte, payload []byte) error {
	n := len(payload)
	_, err := w.Write(append([]byte{byte(n), byte(n >> 8), byte(n >> 16), seq}, payload...))
	return err
}

func myError(w io.Writer, seq byte, code uint16, state, msg string) {
	p := []byte{myErr, byte(code), byte(code >> 8), '#'}
	p = append(p, state...)
	_ = myWrite(w, seq, append(p, msg...))
}

func nulString(b []byte) (string, []byte, bool) {
	i := bytes.IndexByte(b, 0)
	if i < 0 {
		return "", nil, false
	}
	return string(b[:i]), b[i+1:], true
}

func lenenc(b []byte) (uint64, []byte, bool) {
	if len(b) == 0 {
		return 0, nil, false
	}
	switch c := b[0]; {
	case c < 0xFB:
		return uint64(c), b[1:], true
	case c == 0xFC && len(b) >= 3:
		return uint64(binary.LittleEndian.Uint16(b[1:3])), b[3:], true
	case c == 0xFD && len(b) >= 4:
		return uint64(b[1]) | uint64(b[2])<<8 | uint64(b[3])<<16, b[4:], true
	case c == 0xFE && len(b) >= 9:
		return binary.LittleEndian.Uint64(b[1:9]), b[9:], true
	}
	return 0, nil, false
}

func putLenenc(n int) []byte {
	switch {
	case n < 0xFB:
		return []byte{byte(n)}
	case n < 1<<16:
		return []byte{0xFC, byte(n), byte(n >> 8)}
	default:
		return []byte{0xFD, byte(n), byte(n >> 8), byte(n >> 16)}
	}
}

// greeting is a HandshakeV10 offering TLS and caching_sha2_password.
func myGreeting(connID uint32, scramble []byte) []byte {
	caps := uint32(myServerCapabilities)
	p := []byte{10}
	p = append(p, myServerVersion...)
	p = append(p, 0)
	p = binary.LittleEndian.AppendUint32(p, connID)
	p = append(p, scramble[:8]...)
	p = append(p, 0)
	p = binary.LittleEndian.AppendUint16(p, uint16(caps))
	p = append(p, myDefaultCharset)
	p = binary.LittleEndian.AppendUint16(p, 0x0002) // autocommit
	p = binary.LittleEndian.AppendUint16(p, uint16(caps>>16))
	p = append(p, myScrambleLength+1)
	p = append(p, make([]byte, 10)...)
	p = append(p, scramble[8:]...)
	p = append(p, 0)
	p = append(p, mySHA2Plugin...)
	return append(p, 0)
}

// myResponse is a client's HandshakeResponse41.
type myResponse struct {
	caps     uint32
	maxPkt   uint32
	charset  byte
	user     string
	auth     []byte
	database string
	plugin   string
	attrs    []byte // raw, including the lenenc total, when CLIENT_CONNECT_ATTRS
}

func parseMyResponse(p []byte) (*myResponse, error) {
	if len(p) < 32 {
		return nil, errors.New("short handshake response")
	}
	r := &myResponse{caps: binary.LittleEndian.Uint32(p), maxPkt: binary.LittleEndian.Uint32(p[4:]), charset: p[8]}
	if r.caps&myClientProtocol41 == 0 {
		return nil, errors.New("client is too old (needs protocol 4.1)")
	}
	rest := p[32:]
	var ok bool
	if r.user, rest, ok = nulString(rest); !ok {
		return nil, errors.New("bad username")
	}
	switch {
	case r.caps&myClientPluginAuthLenenc != 0:
		var n uint64
		if n, rest, ok = lenenc(rest); !ok || uint64(len(rest)) < n {
			return nil, errors.New("bad auth data")
		}
		r.auth, rest = rest[:n], rest[n:]
	case r.caps&myClientSecureConnection != 0:
		if len(rest) < 1 || len(rest) < 1+int(rest[0]) {
			return nil, errors.New("bad auth data")
		}
		r.auth, rest = rest[1:1+int(rest[0])], rest[1+int(rest[0]):]
	default:
		if s, next, ok := nulString(rest); ok {
			r.auth, rest = []byte(s), next
		}
	}
	if r.caps&myClientConnectWithDB != 0 && len(rest) > 0 {
		r.database, rest, _ = nulString(rest)
	}
	if r.caps&myClientPluginAuth != 0 && len(rest) > 0 {
		r.plugin, rest, _ = nulString(rest)
	}
	if r.caps&myClientConnectAttrs != 0 && len(rest) > 0 {
		r.attrs = rest
	}
	return r, nil
}

// nativeProof is mysql_native_password's answer to scramble.
func nativeProof(password string, scramble []byte) []byte {
	if password == "" {
		return nil
	}
	h1 := sha1.Sum([]byte(password))
	h2 := sha1.Sum(h1[:])
	h := sha1.New()
	h.Write(scramble)
	h.Write(h2[:])
	h3 := h.Sum(nil)
	for i := range h3 {
		h3[i] ^= h1[i]
	}
	return h3
}

// ---- listener ----

func (g *gateway) serveMySQL(certs *certReloader) {
	addr := env("MYSQL_ADDR", ":3306")
	ln, err := net.Listen("tcp", addr)
	if err != nil {
		log.Fatal(err)
	}
	log.Printf("mysql proxy on %s", addr)
	var connID uint32
	for {
		conn, err := ln.Accept()
		if err != nil {
			continue
		}
		connID++
		go g.handleMySQL(conn, certs, connID)
	}
}

func (g *gateway) handleMySQL(raw net.Conn, certs *certReloader, connID uint32) {
	defer raw.Close()
	_ = raw.SetDeadline(time.Now().Add(myHandshakeTimeout))
	scramble := make([]byte, myScrambleLength)
	if _, err := rand.Read(scramble); err != nil {
		return
	}
	for i := range scramble { // the protocol wants no NUL bytes in it
		if scramble[i] == 0 {
			scramble[i] = 1
		}
	}
	if myWrite(raw, 0, myGreeting(connID, scramble)) != nil {
		return
	}
	seq, p, err := myRead(raw)
	if err != nil {
		return
	}
	if len(p) < 4 || binary.LittleEndian.Uint32(p)&myClientSSL == 0 {
		myError(raw, seq+1, myErrHandshake, "08004", "dply databases need TLS (ssl-mode=REQUIRED)")
		return
	}
	client := tls.Server(raw, &tls.Config{GetCertificate: certs.get, MinVersion: tls.VersionTLS12})
	if err := client.Handshake(); err != nil {
		return
	}
	fail := func(seq byte, code uint16, state, msg string) { myError(client, seq, code, state, msg) }
	seq, p, err = myRead(client)
	if err != nil {
		return
	}
	resp, err := parseMyResponse(p)
	if err != nil {
		fail(seq+1, myErrHandshake, "08S01", err.Error())
		return
	}
	// The tenant comes from SNI. Clients that send none (the mysql CLI without
	// --tls-sni-servername) can use the tenant id as the username instead.
	id, ok := tenantFromName(client.ConnectionState().ServerName, g.cfg.dbDomain)
	if !ok && validID(resp.user) {
		id, ok = resp.user, true
	}
	if !ok {
		fail(seq+1, myErrHandshake, "08004", "unknown database host; connect by its dply hostname")
		return
	}
	t, err := g.getTenantRecord(context.Background(), id)
	if err != nil || t.Engine != "mysql" {
		fail(seq+1, myErrHandshake, "08004", "no mysql database at this address")
		return
	}

	// Check the password ourselves.
	password := ""
	switch resp.plugin {
	case mySHA2Plugin, "":
		// Ask for full authentication; inside TLS the client sends the password.
		seq++
		if myWrite(client, seq, []byte{myAuthMoreData, myPerformFullAuth}) != nil {
			return
		}
		if seq, p, err = myRead(client); err != nil {
			return
		}
		password = string(bytes.TrimRight(p, "\x00"))
	case myNativePlugin:
		if subtle.ConstantTimeCompare(resp.auth, nativeProof(t.Password, scramble)) == 1 {
			password = t.Password
		}
	default:
		fail(seq+1, myErrAccessDenied, "28000", "unsupported auth plugin "+resp.plugin)
		return
	}
	if (resp.user != "app" && resp.user != id) || subtle.ConstantTimeCompare([]byte(password), []byte(t.Password)) != 1 {
		fail(seq+1, myErrAccessDenied, "28000", fmt.Sprintf("Access denied for user '%s'", resp.user))
		return
	}

	// A wake (or a brand-new database's first build) can outlast the
	// handshake deadline; the client just waits.
	_ = raw.SetDeadline(time.Now().Add(wakeDeadline))
	ip, err := g.wakeDatabase(context.Background(), id)
	if err != nil {
		log.Printf("tenant %s: wake failed: %v", id, err)
		fail(seq+1, myErrServerUnavailable, "HY000", "this database could not start")
		return
	}
	backend, reply, err := g.mysqlLogin(ip, resp, t.Password)
	if err != nil {
		log.Printf("tenant %s: backend login: %v", id, err)
		fail(seq+1, myErrServerUnavailable, "HY000", "this database is not reachable")
		return
	}
	defer backend.Close()
	// Relay mysqld's OK (or error) with the client's sequence number.
	if myWrite(client, seq+1, reply) != nil || len(reply) == 0 || reply[0] != myOK {
		return
	}
	_ = client.SetDeadline(time.Time{})
	_ = raw.SetDeadline(time.Time{})

	s := g.state(id)
	s.mu.Lock()
	s.conns[client] = struct{}{}
	s.lastActivity = time.Now()
	s.mu.Unlock()
	defer func() {
		s.mu.Lock()
		delete(s.conns, client)
		s.mu.Unlock()
	}()
	done := make(chan struct{}, 2)
	go func() { copyTouching(backend, client, func() { g.touch(id) }); done <- struct{}{} }()
	go func() { copyTouching(client, backend, nil); done <- struct{}{} }()
	<-done
}

// mysqlLogin connects to the tenant's mysqld as "app" with the client's
// capabilities (minus TLS and compression), charset, database and connection
// attributes, so the session is what the client asked for. Returns the
// connection and mysqld's final OK or error packet.
func (g *gateway) mysqlLogin(ip string, client *myResponse, password string) (net.Conn, []byte, error) {
	conn, err := net.DialTimeout("tcp", net.JoinHostPort(ip, dbPorts["mysql"]), 5*time.Second)
	if err != nil {
		return nil, nil, err
	}
	_ = conn.SetDeadline(time.Now().Add(myBackendHandshakeTimeout))
	_, greet, err := myRead(conn)
	if err != nil {
		conn.Close()
		return nil, nil, err
	}
	if len(greet) > 0 && greet[0] == myErr {
		conn.Close()
		return nil, nil, fmt.Errorf("mysqld refused: %q", greet)
	}
	// HandshakeV10: version\0, conn id(4), scramble part 1 (8), 0, caps(2),
	// charset, status(2), caps(2), scramble length, reserved(10), part 2.
	_, rest, ok := nulString(greet[1:])
	if !ok || len(rest) < 4+8+1+2+1+2+2+1+10 {
		conn.Close()
		return nil, nil, errors.New("bad mysqld greeting")
	}
	scramble := append([]byte{}, rest[4:12]...)
	part2 := rest[4+8+1+2+1+2+2+1+10:]
	if i := bytes.IndexByte(part2, 0); i >= 0 {
		part2 = part2[:i]
	}
	scramble = append(scramble, part2...)

	caps := (client.caps &^ (myClientSSL | myClientCompress | myClientZstdCompression)) | myClientProtocol41 | myClientSecureConnection | myClientPluginAuth
	if client.database != "" {
		caps |= myClientConnectWithDB
	} else {
		caps &^= myClientConnectWithDB
	}
	if client.attrs == nil {
		caps &^= myClientConnectAttrs
	}
	proof := nativeProof(password, scramble)
	p := binary.LittleEndian.AppendUint32(nil, caps)
	p = binary.LittleEndian.AppendUint32(p, client.maxPkt)
	p = append(p, client.charset)
	p = append(p, make([]byte, 23)...)
	p = append(p, "app"...)
	p = append(p, 0)
	if caps&myClientPluginAuthLenenc != 0 {
		p = append(p, putLenenc(len(proof))...)
	} else {
		p = append(p, byte(len(proof)))
	}
	p = append(p, proof...)
	if client.database != "" {
		p = append(p, client.database...)
		p = append(p, 0)
	}
	p = append(p, myNativePlugin...)
	p = append(p, 0)
	if client.attrs != nil {
		p = append(p, client.attrs...)
	}
	if err := myWrite(conn, 1, p); err != nil {
		conn.Close()
		return nil, nil, err
	}
	for {
		seq, reply, err := myRead(conn)
		if err != nil {
			conn.Close()
			return nil, nil, err
		}
		if len(reply) > 0 && reply[0] == myAuthSwitch {
			// Switch to mysql_native_password with a new scramble.
			plugin, data, ok := nulString(reply[1:])
			if !ok || plugin != myNativePlugin {
				conn.Close()
				return nil, nil, fmt.Errorf("mysqld asked for %s", plugin)
			}
			if err := myWrite(conn, seq+1, nativeProof(password, bytes.TrimRight(data, "\x00"))); err != nil {
				conn.Close()
				return nil, nil, err
			}
			continue
		}
		_ = conn.SetDeadline(time.Time{})
		return conn, reply, nil
	}
}
