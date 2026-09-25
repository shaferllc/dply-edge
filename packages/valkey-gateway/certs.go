package main

import (
	"crypto/tls"
	"os"
	"sync"
	"time"
)

// certReloader serves the certificate on disk and picks up a renewal.
// cert-manager rewrites the mounted Secret around day 60 of a 90-day
// Let's Encrypt cert; loading it once at start would keep serving the old
// one until every tenant's TLS failed.
type certReloader struct {
	certFile, keyFile string

	mu      sync.Mutex
	modTime time.Time
	cert    *tls.Certificate
}

func newCertReloader(certFile, keyFile string) (*certReloader, error) {
	r := &certReloader{certFile: certFile, keyFile: keyFile}
	if _, err := r.get(nil); err != nil {
		return nil, err
	}
	return r, nil
}

// get is a tls.Config.GetCertificate: one stat per handshake, a reload only
// when the file changed. A failed reload keeps the last good cert.
func (r *certReloader) get(*tls.ClientHelloInfo) (*tls.Certificate, error) {
	info, err := os.Stat(r.certFile)
	r.mu.Lock()
	defer r.mu.Unlock()
	if err == nil && (r.cert == nil || info.ModTime() != r.modTime) {
		if cert, loadErr := tls.LoadX509KeyPair(r.certFile, r.keyFile); loadErr == nil {
			r.cert, r.modTime = &cert, info.ModTime()
		} else if r.cert == nil {
			return nil, loadErr
		}
	}
	if r.cert == nil {
		return nil, err
	}
	return r.cert, nil
}
