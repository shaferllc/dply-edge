package main

import (
	"bytes"
	"context"
	"net/url"
	"os"
	"time"

	"github.com/minio/minio-go/v7"
	"github.com/minio/minio-go/v7/pkg/credentials"
)

// snapshotStore keeps one RDB per tenant in an S3 bucket: R2 in production,
// SeaweedFS locally. Key: tenants/{id}/dump.rdb.
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
	s := &snapshotStore{client: client, bucket: env("S3_BUCKET", "dply-valkey")}
	ctx := context.Background()
	if ok, err := client.BucketExists(ctx, s.bucket); err == nil && !ok {
		_ = client.MakeBucket(ctx, s.bucket, minio.MakeBucketOptions{})
	}
	return s, nil
}

func key(id string) string { return "tenants/" + id + "/dump.rdb" }

func (s *snapshotStore) put(ctx context.Context, id string, rdb []byte) error {
	upload := func() error {
		_, err := s.client.PutObject(ctx, s.bucket, key(id), bytes.NewReader(rdb), int64(len(rdb)), minio.PutObjectOptions{ContentType: "application/octet-stream"})
		return err
	}
	err := upload()
	if minio.ToErrorResponse(err).Code == "NoSuchBucket" {
		if mkErr := s.client.MakeBucket(ctx, s.bucket, minio.MakeBucketOptions{}); mkErr != nil {
			return mkErr
		}
		err = upload()
	}
	return err
}

func (s *snapshotStore) exists(ctx context.Context, id string) bool {
	_, err := s.client.StatObject(ctx, s.bucket, key(id), minio.StatObjectOptions{})
	return err == nil
}

func (s *snapshotStore) remove(ctx context.Context, id string) error {
	return s.client.RemoveObject(ctx, s.bucket, key(id), minio.RemoveObjectOptions{})
}

// presignGet is a short-lived URL the restore init container downloads from.
func (s *snapshotStore) presignGet(ctx context.Context, id string) (string, error) {
	u, err := s.client.PresignedGetObject(ctx, s.bucket, key(id), 5*time.Minute, nil)
	if err != nil {
		return "", err
	}
	return u.String(), nil
}
