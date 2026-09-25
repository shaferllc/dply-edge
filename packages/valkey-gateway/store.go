package main

import (
	"context"
	"io"
	"net/url"
	"os"

	"github.com/minio/minio-go/v7"
	"github.com/minio/minio-go/v7/pkg/credentials"
)

// snapshotStore keeps one key dump per tenant in an S3 bucket: R2 in
// production, SeaweedFS locally. Key: tenants/{id}/keys.dump.
type snapshotStore struct {
	client *minio.Client
	bucket string
}

func newSnapshotStore() (*snapshotStore, error) {
	endpoint, _ := url.Parse(env("S3_ENDPOINT", "http://s3:9000"))
	client, err := minio.New(endpoint.Host, &minio.Options{
		Creds:  credentials.NewStaticV4(os.Getenv("S3_ACCESS_KEY"), os.Getenv("S3_SECRET_KEY"), ""),
		Secure: endpoint.Scheme == "https",
		Region: env("S3_REGION", "auto"),
	})
	if err != nil {
		return nil, err
	}
	return &snapshotStore{client: client, bucket: env("S3_BUCKET", "dply-valkey")}, nil
}

func key(id string) string { return "tenants/" + id + "/keys.dump" }

// put streams a snapshot of unknown length into the bucket.
func (s *snapshotStore) put(ctx context.Context, id string, body io.Reader) error {
	upload := func() error {
		_, err := s.client.PutObject(ctx, s.bucket, key(id), body, -1, minio.PutObjectOptions{ContentType: "application/octet-stream", PartSize: 16 << 20})
		return err
	}
	if ok, err := s.client.BucketExists(ctx, s.bucket); err == nil && !ok {
		if err := s.client.MakeBucket(ctx, s.bucket, minio.MakeBucketOptions{}); err != nil {
			return err
		}
	}
	return upload()
}

// get opens the tenant's snapshot, or returns nil when there is none.
func (s *snapshotStore) get(ctx context.Context, id string) (io.ReadCloser, error) {
	if !s.exists(ctx, id) {
		return nil, nil
	}
	return s.client.GetObject(ctx, s.bucket, key(id), minio.GetObjectOptions{})
}

func (s *snapshotStore) exists(ctx context.Context, id string) bool {
	_, err := s.client.StatObject(ctx, s.bucket, key(id), minio.StatObjectOptions{})
	return err == nil
}

func (s *snapshotStore) remove(ctx context.Context, id string) error {
	return s.client.RemoveObject(ctx, s.bucket, key(id), minio.RemoveObjectOptions{})
}
