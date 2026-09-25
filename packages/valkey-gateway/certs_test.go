package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"math/big"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func writeCert(t *testing.T, dir, cn string, mod time.Time) {
	t.Helper()
	key, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tmpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: cn}, NotAfter: time.Now().Add(time.Hour)}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	keyDER, _ := x509.MarshalECPrivateKey(key)
	certPath, keyPath := filepath.Join(dir, "tls.crt"), filepath.Join(dir, "tls.key")
	_ = os.WriteFile(keyPath, pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: keyDER}), 0o600)
	_ = os.WriteFile(certPath, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0o600)
	_ = os.Chtimes(certPath, mod, mod)
}

func servedCN(t *testing.T, r *certReloader) string {
	t.Helper()
	c, err := r.get(nil)
	if err != nil {
		t.Fatal(err)
	}
	leaf, _ := x509.ParseCertificate(c.Certificate[0])
	return leaf.Subject.CommonName
}

func TestCertReloaderPicksUpRenewal(t *testing.T) {
	dir := t.TempDir()
	start := time.Now().Add(-time.Hour)
	writeCert(t, dir, "first", start)
	r, err := newCertReloader(filepath.Join(dir, "tls.crt"), filepath.Join(dir, "tls.key"))
	if err != nil {
		t.Fatal(err)
	}
	if got := servedCN(t, r); got != "first" {
		t.Fatalf("got %q, want first", got)
	}

	writeCert(t, dir, "renewed", start.Add(time.Minute))
	if got := servedCN(t, r); got != "renewed" {
		t.Fatalf("after renewal got %q, want renewed", got)
	}

	// A half-written renewal keeps the last good cert.
	_ = os.WriteFile(filepath.Join(dir, "tls.crt"), []byte("garbage"), 0o600)
	_ = os.Chtimes(filepath.Join(dir, "tls.crt"), start.Add(2*time.Minute), start.Add(2*time.Minute))
	if got := servedCN(t, r); got != "renewed" {
		t.Fatalf("after bad write got %q, want renewed", got)
	}
}
