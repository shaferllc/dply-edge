package main

import (
	"bytes"
	"compress/gzip"
	"context"
	"fmt"
	"io"
	"log"
	"net/url"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"github.com/minio/minio-go/v7"
	"github.com/minio/minio-go/v7/pkg/credentials"
)

// Backups (MongoDB, MySQL): a daily logical dump of the app's database,
// gzipped, to the same bucket and credentials wal-g uses for Postgres
// (WALG_S3_PREFIX, e.g. s3://bucket/tenants/{id}/mongodb), keeping 7, plus
// the engine's change log (MySQL binlog, MongoDB oplog) shipped each minute
// there is something new. Restore loads the newest dump at or before the
// target, replays the log from that dump's position up to the target, then
// takes a fresh dump as the new base. The current data is dumped first so a
// restore can itself be undone.

// dumper is an engine that can write and load a logical dump of "app".
type dumper interface {
	initialized() bool // the first start's setup is done (admin login exists)
	running() bool
	dump(w io.Writer) error
	load(r io.Reader) error
}

// logShipper is an engine with a change log to replay dumps forward.
// Positions are opaque strings the engine writes and reads back.
type logShipper interface {
	dumpPosition() string                       // log position the last dump is consistent with
	ship(d *dumps) error                        // upload new log since the last call
	replay(d *dumps, from, target string) error // apply log after from, up to target (RFC3339; empty: all)
	prune(d *dumps, before string) error        // drop log older than position before
}

type dumps struct {
	e      dumper
	marker string
	client *minio.Client
	bucket string
	prefix string // "tenants/{id}/mongodb/"
}

const dumpKeep = 7

// newDumps is nil when backups are not configured (local).
func newDumps(e dumper, marker string) (*dumps, error) {
	if !backupsEnabled() {
		return nil, nil
	}
	target, err := url.Parse(os.Getenv("WALG_S3_PREFIX"))
	if err != nil || target.Scheme != "s3" || target.Host == "" {
		return nil, fmt.Errorf("WALG_S3_PREFIX must be s3://bucket/prefix")
	}
	endpoint, err := url.Parse(os.Getenv("AWS_ENDPOINT"))
	if err != nil || endpoint.Host == "" {
		return nil, fmt.Errorf("AWS_ENDPOINT must be a URL")
	}
	client, err := minio.New(endpoint.Host, &minio.Options{
		Creds:  credentials.NewStaticV4(os.Getenv("AWS_ACCESS_KEY_ID"), os.Getenv("AWS_SECRET_ACCESS_KEY"), ""),
		Secure: endpoint.Scheme == "https",
		Region: envOr("AWS_REGION", "auto"),
	})
	if err != nil {
		return nil, err
	}
	return &dumps{e: e, marker: marker, client: client, bucket: target.Host, prefix: strings.Trim(target.Path, "/") + "/"}, nil
}

// loop dumps when the database is up and the last dump is over a day old.
// An idle sleep waits for it (backupMu); a forced one (resize, pod stop)
// fails it, and the next tick after a wake retries.
func (d *dumps) loop() {
	for {
		backupMu.Lock()
		up := d.e.initialized() && d.e.running()
		if s, ok := d.e.(logShipper); ok && up {
			// A log failure shows as a failed backup until the next good dump.
			if err := s.ship(d); err != nil {
				recordBackup(err)
				log.Printf("log backup: %v", err)
			}
		}
		if up && dueSince(d.marker, 24*time.Hour) {
			started := time.Now()
			err := d.backupLocked(true)
			recordBackup(err)
			if err != nil {
				log.Printf("backup: %v", err)
			} else {
				log.Printf("backup: done in %s", time.Since(started).Round(time.Second))
			}
		}
		backupMu.Unlock()
		time.Sleep(time.Minute)
	}
}

// backupLocked dumps now; prune drops all but the newest dumpKeep. A restore
// does not prune, so the dump it is about to load is never removed.
func (d *dumps) backupLocked(prune bool) error {
	now := time.Now().UTC()
	pr, pw := io.Pipe()
	go func() {
		gz := gzip.NewWriter(pw)
		err := d.e.dump(gz)
		if cerr := gz.Close(); err == nil {
			err = cerr
		}
		_ = pw.CloseWithError(err)
	}()
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Minute)
	defer cancel()
	key := d.prefix + "dump-" + now.Format("20060102T150405Z") + ".gz"
	if _, err := d.client.PutObject(ctx, d.bucket, key, pr, -1, minio.PutObjectOptions{ContentType: "application/gzip", PartSize: 16 << 20}); err != nil {
		_ = pr.CloseWithError(err)
		return err
	}
	_ = os.WriteFile(d.marker, []byte(now.Format(time.RFC3339)), 0o600)
	s, shipping := d.e.(logShipper)
	if shipping {
		if pos := s.dumpPosition(); pos != "" {
			if err := d.putBytes(ctx, key+".pos", []byte(pos)); err != nil {
				return fmt.Errorf("dump position: %v", err)
			}
		}
	}
	if !prune {
		return nil
	}

	keys, err := d.list(ctx)
	if err != nil {
		return fmt.Errorf("retention: %v", err)
	}
	for i := 0; i < len(keys)-dumpKeep; i++ {
		for _, k := range []string{keys[i].key, keys[i].key + ".pos"} {
			if err := d.client.RemoveObject(ctx, d.bucket, k, minio.RemoveObjectOptions{}); err != nil {
				return fmt.Errorf("retention: %v", err)
			}
		}
	}
	// The log is only useful from the oldest kept dump on.
	if shipping && len(keys) > 0 {
		oldest := keys[max(0, len(keys)-dumpKeep)].key
		if pos := d.getString(ctx, oldest+".pos"); pos != "" {
			if err := s.prune(d, pos); err != nil {
				return fmt.Errorf("log retention: %v", err)
			}
		}
	}
	return nil
}

func (d *dumps) putBytes(ctx context.Context, key string, b []byte) error {
	_, err := d.client.PutObject(ctx, d.bucket, key, bytes.NewReader(b), int64(len(b)), minio.PutObjectOptions{})
	return err
}

func (d *dumps) putFile(ctx context.Context, key, path string) error {
	_, err := d.client.FPutObject(ctx, d.bucket, key, path, minio.PutObjectOptions{PartSize: 16 << 20})
	return err
}

func (d *dumps) getFile(ctx context.Context, key, path string) error {
	return d.client.FGetObject(ctx, d.bucket, key, path, minio.GetObjectOptions{})
}

// getString is a small object's content, or "" if it is missing.
func (d *dumps) getString(ctx context.Context, key string) string {
	obj, err := d.client.GetObject(ctx, d.bucket, key, minio.GetObjectOptions{})
	if err != nil {
		return ""
	}
	defer obj.Close()
	b, err := io.ReadAll(io.LimitReader(obj, 4096))
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(b))
}

// keys under prefix+sub, sorted by name.
func (d *dumps) keys(ctx context.Context, sub string) ([]string, error) {
	var out []string
	for obj := range d.client.ListObjects(ctx, d.bucket, minio.ListObjectsOptions{Prefix: d.prefix + sub, Recursive: true}) {
		if obj.Err != nil {
			return nil, obj.Err
		}
		out = append(out, obj.Key)
	}
	sort.Strings(out)
	return out, nil
}

func (d *dumps) remove(ctx context.Context, key string) error {
	return d.client.RemoveObject(ctx, d.bucket, key, minio.RemoveObjectOptions{})
}

type dumpKey struct {
	key string
	at  time.Time
}

// list is every dump, oldest first.
func (d *dumps) list(ctx context.Context) ([]dumpKey, error) {
	var out []dumpKey
	for obj := range d.client.ListObjects(ctx, d.bucket, minio.ListObjectsOptions{Prefix: d.prefix + "dump-"}) {
		if obj.Err != nil {
			return nil, obj.Err
		}
		if !strings.HasSuffix(obj.Key, ".gz") {
			continue // a dump's .pos
		}
		stamp := strings.TrimSuffix(strings.TrimPrefix(obj.Key, d.prefix+"dump-"), ".gz")
		if at, err := time.Parse("20060102T150405Z", stamp); err == nil {
			out = append(out, dumpKey{obj.Key, at})
		}
	}
	sort.Slice(out, func(i, j int) bool { return out[i].at.Before(out[j].at) })
	return out, nil
}

// restore loads the newest dump at or before target (RFC3339; empty is the
// newest). The database must be running (the gateway wakes it first).
// The caller holds backupMu.
func (d *dumps) restore(target string) error {
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Minute)
	defer cancel()
	s, shipping := d.e.(logShipper)
	if shipping {
		if err := s.ship(d); err != nil {
			return fmt.Errorf("saving the latest changes first: %v", err)
		}
	}
	keys, err := d.list(ctx)
	if err != nil {
		return err
	}
	chosen := ""
	at, _ := time.Parse(time.RFC3339, target)
	for _, k := range keys {
		if target == "" || !k.at.After(at) {
			chosen = k.key
		}
	}
	if chosen == "" {
		return fmt.Errorf("no backup at or before %s", target)
	}
	// Keep the current data as a dump of its own, so this restore can be undone.
	if err := d.backupLocked(false); err != nil {
		return fmt.Errorf("saving the current data first: %v", err)
	}
	obj, err := d.client.GetObject(ctx, d.bucket, chosen, minio.GetObjectOptions{})
	if err != nil {
		return err
	}
	defer obj.Close()
	gz, err := gzip.NewReader(obj)
	if err != nil {
		return fmt.Errorf("%s: %v", chosen, err)
	}
	log.Printf("restore: loading %s", filepath.Base(chosen))
	if err := d.e.load(gz); err != nil {
		return err
	}
	if !shipping {
		return nil
	}
	if pos := d.getString(ctx, chosen+".pos"); pos != "" {
		log.Printf("restore: replaying changes from %s to %q", pos, target)
		if err := s.replay(d, pos, target); err != nil {
			return fmt.Errorf("replaying changes: %v", err)
		}
	}
	// The restored data is not in the log, so it needs a base of its own.
	return d.backupLocked(true)
}

// dueSince: the marker file's time is missing or older than every.
func dueSince(marker string, every time.Duration) bool {
	b, err := os.ReadFile(marker)
	if err != nil {
		return true
	}
	at, err := time.Parse(time.RFC3339, strings.TrimSpace(string(b)))
	return err != nil || time.Since(at) > every
}
