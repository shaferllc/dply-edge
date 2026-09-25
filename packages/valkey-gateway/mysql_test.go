package main

import (
	"bytes"
	"crypto/sha1"
	"encoding/binary"
	"testing"
)

// A server verifies mysql_native_password by recovering SHA1(password) from
// the proof and checking SHA1 of it against the stored SHA1(SHA1(password)).
func TestNativeProofVerifiesLikeMySQL(t *testing.T) {
	scramble := []byte("abcdefghijklmnopqrst")
	proof := nativeProof("s3cret-password", scramble)
	h1 := sha1.Sum([]byte("s3cret-password"))
	stored := sha1.Sum(h1[:])
	h := sha1.New()
	h.Write(scramble)
	h.Write(stored[:])
	mask := h.Sum(nil)
	recovered := make([]byte, len(proof))
	for i := range proof {
		recovered[i] = proof[i] ^ mask[i]
	}
	if got := sha1.Sum(recovered); !bytes.Equal(got[:], stored[:]) {
		t.Fatal("proof does not verify")
	}
	if nativeProof("", scramble) != nil {
		t.Fatal("empty password must send an empty proof")
	}
}

func TestParseHandshakeResponse(t *testing.T) {
	caps := uint32(myClientProtocol41 | myClientSecureConnection | myClientPluginAuth | myClientPluginAuthLenenc | myClientConnectWithDB | myClientConnectAttrs | myClientSSL)
	p := binary.LittleEndian.AppendUint32(nil, caps)
	p = binary.LittleEndian.AppendUint32(p, 1<<24)
	p = append(p, 45)
	p = append(p, make([]byte, 23)...)
	p = append(append(p, "app"...), 0)
	p = append(p, putLenenc(3)...)
	p = append(p, 1, 2, 3)
	p = append(append(p, "app"...), 0)
	p = append(append(p, mySHA2Plugin...), 0)
	attrs := []byte{5, 2, 'k', 1, 'v', 0}
	p = append(p, attrs...)

	r, err := parseMyResponse(p)
	if err != nil {
		t.Fatal(err)
	}
	if r.user != "app" || !bytes.Equal(r.auth, []byte{1, 2, 3}) || r.database != "app" || r.plugin != mySHA2Plugin || r.charset != 45 || !bytes.Equal(r.attrs, attrs) {
		t.Fatalf("parsed %+v", r)
	}
	if _, err := parseMyResponse(p[:10]); err == nil {
		t.Fatal("short response must fail")
	}
}

func TestGreetingOffersTLSAndSHA2(t *testing.T) {
	scramble := bytes.Repeat([]byte{'x'}, myScrambleLength)
	g := myGreeting(7, scramble)
	if g[0] != 10 || !bytes.HasSuffix(g, append([]byte(mySHA2Plugin), 0)) {
		t.Fatal("greeting shape")
	}
	_, rest, _ := nulString(g[1:])
	lower := binary.LittleEndian.Uint16(rest[4+8+1:])
	if uint32(lower)&myClientSSL == 0 {
		t.Fatal("greeting must offer TLS")
	}
}
