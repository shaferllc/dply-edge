package main

import (
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
// (WALG_S3_PREFIX, e.g. s3://bucket/tenants/{id}/mongodb), keeping 7.
// Restore loads the newest dump taken at or before the target time, after
// dumping the current data first so a restore can itself be undone.
// ponytail: whole-database dumps, not point in time; add oplog/binlog
// streaming if daily granularity is not enough.

// dumper is an engine that can write and load a logical dump of "app".
type dumper interface {
	running() bool
	dump(w io.Writer) error
	load(r io.Reader) error
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
		if d.e.running() && dueSince(d.marker, 24*time.Hour) {
			started := time.Now()
			if err := d.backupLocked(true); err != nil {
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
	if !prune {
		return nil
	}

	keys, err := d.list(ctx)
	if err != nil {
		return fmt.Errorf("retention: %v", err)
	}
	for i := 0; i < len(keys)-dumpKeep; i++ {
		if err := d.client.RemoveObject(ctx, d.bucket, keys[i].key, minio.RemoveObjectOptions{}); err != nil {
			return fmt.Errorf("retention: %v", err)
		}
	}
	return nil
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
	return d.e.load(gz)
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
