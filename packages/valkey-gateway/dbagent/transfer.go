package main

import (
	"bufio"
	"compress/gzip"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/url"
	"os"
	"os/exec"
	"path"
	"regexp"
	"strings"
	"time"

	"github.com/minio/minio-go/v7"
	"github.com/minio/minio-go/v7/pkg/credentials"
)

// Export and import: a logical copy of "app" in the backup bucket, under
// tenants/{id}/exports/ (what this database exported) and
// tenants/{id}/imports/ (a file someone uploaded). A database branch and a
// move to another region are an export here and an import into a new
// database. Both stream; neither needs room on the data volume.
//
// Imports the customer uploaded are Postgres custom-format dumps (restored
// as the app's own role, which cannot run programs) and MongoDB archives.
// MySQL loads only dply's own exports: its client can read local files
// (source), and this pod holds the bucket's keys.

var importKey = regexp.MustCompile(`^tenants/[a-z0-9-]{3,40}/(exports|imports)/[A-Za-z0-9._-]{1,120}$`)

type bucket struct {
	client *minio.Client
	name   string
	root   string // "tenants/{id}/"
}

// openBucket uses the same bucket and keys as the backups (WALG_S3_PREFIX is
// s3://bucket/tenants/{id}/{engine}).
func openBucket() (*bucket, error) {
	if !backupsEnabled() {
		return nil, errors.New("backups are not configured, so there is nowhere to export to")
	}
	target, err := url.Parse(os.Getenv("WALG_S3_PREFIX"))
	if err != nil || target.Scheme != "s3" || target.Host == "" {
		return nil, errors.New("WALG_S3_PREFIX must be s3://bucket/prefix")
	}
	endpoint, err := url.Parse(os.Getenv("AWS_ENDPOINT"))
	if err != nil || endpoint.Host == "" {
		return nil, errors.New("AWS_ENDPOINT must be a URL")
	}
	client, err := minio.New(endpoint.Host, &minio.Options{
		Creds:  credentials.NewStaticV4(os.Getenv("AWS_ACCESS_KEY_ID"), os.Getenv("AWS_SECRET_ACCESS_KEY"), ""),
		Secure: endpoint.Scheme == "https",
		Region: envOr("AWS_REGION", "auto"),
	})
	if err != nil {
		return nil, err
	}
	return &bucket{client: client, name: target.Host, root: path.Dir(strings.Trim(target.Path, "/")) + "/"}, nil
}

func transfer(e engine, name string, body []byte) (any, error) {
	b, err := openBucket()
	if err != nil {
		return nil, err
	}
	if name == "export" {
		return b.export(e)
	}
	var in struct{ Key string }
	if err := json.Unmarshal(body, &in); err != nil || !importKey.MatchString(in.Key) {
		return nil, errors.New("a key under tenants/{id}/exports/ or imports/ is required")
	}
	return nil, b.importFrom(e, in.Key)
}

type countingWriter struct {
	w io.Writer
	n int64
}

func (c *countingWriter) Write(p []byte) (int, error) {
	n, err := c.w.Write(p)
	c.n += int64(n)
	return n, err
}

func (b *bucket) export(e engine) (any, error) {
	ctx, cancel := context.WithTimeout(context.Background(), time.Hour)
	defer cancel()
	stamp := time.Now().UTC().Format("20060102T150405Z")
	var key, format string
	var produce func(w io.Writer) error
	switch x := e.(type) {
	case *postgres:
		key, format = b.root+"exports/"+stamp+".pgdump", "pg_dump custom (pg_restore)"
		produce = func(w io.Writer) error {
			cmd := exec.Command("pg_dump", "-h", x.run, "-U", "dply_admin", "-d", "app", "-Fc", "-Z", "6", "--no-owner", "--no-acl")
			cmd.Stdout = w
			return runCaptured(cmd)
		}
	case *mysqlEngine:
		key, format, produce = b.root+"exports/"+stamp+".sql.gz", "mysqldump, gzip", gzipped(x)
	case *mongo:
		key, format, produce = b.root+"exports/"+stamp+".archive.gz", "mongodump --archive, gzip", gzipped(x)
	default:
		return nil, errors.New("export is not available for this engine")
	}
	pr, pw := io.Pipe()
	counter := &countingWriter{w: pw}
	go func() { _ = pw.CloseWithError(produce(counter)) }()
	if _, err := b.client.PutObject(ctx, b.name, key, pr, -1, minio.PutObjectOptions{ContentType: "application/octet-stream", PartSize: 16 << 20}); err != nil {
		_ = pr.CloseWithError(err)
		return nil, err
	}
	return map[string]any{"key": key, "bytes": counter.n, "format": format}, nil
}

func gzipped(d dumper) func(w io.Writer) error {
	return func(w io.Writer) error {
		gz := gzip.NewWriter(w)
		if err := d.dump(gz); err != nil {
			return err
		}
		return gz.Close()
	}
}

func (m *mysqlEngine) backupsOf() *dumps { return m.backups }
func (m *mongo) backupsOf() *dumps       { return m.backups }

func (b *bucket) open(ctx context.Context, key string) (io.ReadCloser, error) {
	obj, err := b.client.GetObject(ctx, b.name, key, minio.GetObjectOptions{})
	if err != nil {
		return nil, err
	}
	if _, err := obj.Stat(); err != nil {
		obj.Close()
		return nil, fmt.Errorf("%s: %v", path.Base(key), err)
	}
	return obj, nil
}

// maybeGunzip unwraps gzip when the stream starts with its magic bytes.
func maybeGunzip(r io.Reader) (io.Reader, error) {
	br := bufio.NewReader(r)
	head, _ := br.Peek(2)
	if len(head) == 2 && head[0] == 0x1f && head[1] == 0x8b {
		return gzip.NewReader(br)
	}
	return br, nil
}

func (b *bucket) importFrom(e engine, key string) error {
	ctx, cancel := context.WithTimeout(context.Background(), time.Hour)
	defer cancel()
	switch x := e.(type) {
	case *postgres:
		return b.importPostgres(ctx, x, key)
	case *mysqlEngine:
		if !strings.Contains(key, "/exports/") {
			return errors.New("MySQL can only load dply's own exports")
		}
	}
	d, ok := e.(dumper)
	if !ok {
		return errors.New("import is not available for this engine")
	}
	obj, err := b.open(ctx, key)
	if err != nil {
		return err
	}
	defer obj.Close()
	r, err := maybeGunzip(obj)
	if err != nil {
		return err
	}
	if err := d.load(r); err != nil {
		return err
	}
	// The loaded data is not in the change log: give it a base of its own.
	if withBackups, ok := e.(interface{ backupsOf() *dumps }); ok && withBackups.backupsOf() != nil {
		err := withBackups.backupsOf().backupLocked(true)
		recordBackup(err)
	}
	return nil
}

var dumpExtension = regexp.MustCompile(`(?m)^\d+; \d+ \d+ EXTENSION - (\S+)`)

// importPostgres restores a custom-format dump as the app's role, after
// creating (as the admin) any offered extension the dump needs.
func (b *bucket) importPostgres(ctx context.Context, p *postgres, key string) error {
	obj, err := b.open(ctx, key)
	if err != nil {
		return err
	}
	br := bufio.NewReader(obj)
	if head, _ := br.Peek(5); string(head) != "PGDMP" {
		obj.Close()
		return errors.New("not a pg_dump custom-format file (make it with pg_dump -Fc)")
	}
	list := exec.Command("pg_restore", "-l")
	list.Stdin = br
	toc, err := list.Output()
	obj.Close()
	if err != nil {
		return fmt.Errorf("reading the dump: %v", err)
	}
	for _, m := range dumpExtension.FindAllStringSubmatch(string(toc), -1) {
		if contains(pgExtensions, m[1]) {
			_ = run("psql", "-h", p.run, "-U", "dply_admin", "-d", "app", "-qAtc", `CREATE EXTENSION IF NOT EXISTS "`+m[1]+`"`)
		}
	}
	obj, err = b.open(ctx, key)
	if err != nil {
		return err
	}
	defer obj.Close()
	cmd := exec.Command("pg_restore", "-h", p.run, "-U", "app", "-d", "app", "--no-owner", "--no-acl", "--clean", "--if-exists")
	cmd.Stdin = obj
	var stderr strings.Builder
	cmd.Stderr = &stderr
	if err := cmd.Run(); err != nil {
		// pg_restore carries on past statements the app's role may not run
		// (a comment on the public schema, an extension not offered) and
		// says so at the end. Anything else is a failure.
		if !strings.Contains(stderr.String(), "errors ignored on restore") {
			return fmt.Errorf("pg_restore: %v: %s", err, lastLines(stderr.String(), 3))
		}
	}
	return p.readOnlyRole()
}
