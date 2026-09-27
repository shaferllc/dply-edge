<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use Aws\CommandInterface;
use Aws\CommandPool;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\LazyOpenStream;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Walks a local artifact directory and uploads every file to the
 * configured Edge R2 disk under a storage prefix.
 */
class EdgeArtifactPublisher
{
    /**
     * On an S3/R2 disk the files go up concurrently (`edge.r2.upload_concurrency`),
     * streamed from disk. A file whose MD5 matches the ETag of the same path
     * under $previousPrefix is server-side copied from there instead of
     * re-uploaded; a copy that fails falls back to an upload. Any upload
     * failure throws — a deploy never goes live with a partial artifact set.
     */
    public function uploadDirectory(string $localArtifactDir, string $storagePrefix, ?string $diskName = null, ?string $previousPrefix = null): int
    {
        if (! is_dir($localArtifactDir)) {
            throw new RuntimeException("Artifact directory not found: {$localArtifactDir}");
        }

        $disk = $this->disk($diskName);
        $prefix = trim($storagePrefix, '/');
        $files = $this->localFiles($localArtifactDir);

        if (! $disk instanceof AwsS3V3Adapter) {
            // Local / faked disks: sequential, same options as the S3 path.
            foreach ($files as $relative => $path) {
                $stream = fopen($path, 'rb');
                try {
                    $ok = $disk->writeStream($prefix.'/'.$relative, $stream, [
                        'visibility' => 'public',
                        'CacheControl' => $this->cacheControlFor($relative),
                        'ContentType' => $this->mimeFor($relative),
                    ]);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                if ($ok === false) {
                    throw new RuntimeException("Failed to upload artifact {$relative}.");
                }
            }

            return count($files);
        }

        $client = $disk->getClient();
        $bucket = (string) $disk->getConfig()['bucket'];
        $previous = trim((string) $previousPrefix, '/');
        $previousEtags = ($previous !== '' && $previous !== $prefix)
            ? $this->etagsUnder($client, $bucket, rtrim($disk->path($previous), '/').'/') // path() drops a trailing slash
            : [];

        $put = fn (string $relative) => $client->getCommand('PutObject', [
            'Bucket' => $bucket,
            'Key' => $disk->path($prefix.'/'.$relative),
            'Body' => new LazyOpenStream($files[$relative], 'rb'),
            'ACL' => 'public-read',
            'CacheControl' => $this->cacheControlFor($relative),
            'ContentType' => $this->mimeFor($relative),
        ]);

        $commands = function () use ($files, $previousEtags, $previous, $client, $bucket, $disk, $prefix, $put) {
            foreach ($files as $relative => $path) {
                $etag = $previousEtags[$relative] ?? null;
                if ($etag !== null && hash_equals($etag, (string) md5_file($path))) {
                    // REPLACE, not COPY: headers are recomputed from the path
                    // exactly as an upload would set them.
                    yield $relative => $client->getCommand('CopyObject', [
                        'Bucket' => $bucket,
                        'Key' => $disk->path($prefix.'/'.$relative),
                        'CopySource' => $bucket.'/'.S3Client::encodeKey($disk->path($previous.'/'.$relative)),
                        'MetadataDirective' => 'REPLACE',
                        'ACL' => 'public-read',
                        'CacheControl' => $this->cacheControlFor($relative),
                        'ContentType' => $this->mimeFor($relative),
                    ]);
                } else {
                    yield $relative => $put($relative);
                }
            }
        };

        $failed = $this->runPool($client, $commands());

        // A reuse copy can fail legitimately (source pruned meanwhile) —
        // upload those instead. Anything that fails as an upload is fatal.
        $retry = array_keys(array_filter($failed, fn (array $f) => $f['command'] === 'CopyObject'));
        $fatal = array_diff_key($failed, array_flip($retry));
        if ($retry !== []) {
            // If every deploy logs this, R2 is rejecting the reuse copies.
            Log::warning('Edge artifact reuse copies failed; re-uploading', [
                'count' => count($retry),
                'first_error' => $failed[$retry[0]]['error'],
            ]);
            $fatal += $this->runPool($client, (function () use ($retry, $put) {
                foreach ($retry as $relative) {
                    yield $relative => $put($relative);
                }
            })());
        }

        if ($fatal !== []) {
            $first = reset($fatal);

            throw new RuntimeException(sprintf(
                'Failed to upload %d of %d artifact(s) to R2 (first: %s: %s).',
                count($fatal), count($files), (string) array_key_first($fatal), $first['error'],
            ));
        }

        return count($files);
    }

    public function directoryBytes(string $localArtifactDir): int
    {
        if (! is_dir($localArtifactDir)) {
            return 0;
        }

        $bytes = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localArtifactDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $bytes += (int) $file->getSize();
            }
        }

        return $bytes;
    }

    public function uploadFile(string $localPath, string $storageKey, ?string $diskName = null): void
    {
        if (! is_file($localPath)) {
            throw new RuntimeException("Build log file not found: {$localPath}");
        }

        $disk = $this->disk($diskName);
        $disk->put(trim($storageKey, '/'), file_get_contents($localPath), [
            'ContentType' => 'text/plain; charset=utf-8',
        ]);
    }

    public function readFile(string $storageKey, ?string $diskName = null): ?string
    {
        $disk = $this->disk($diskName);
        $key = trim($storageKey, '/');
        if (! $disk->exists($key)) {
            return null;
        }

        return $disk->get($key);
    }

    public function deletePrefix(string $storagePrefix, ?string $diskName = null): void
    {
        $disk = $this->disk($diskName);
        $disk->deleteDirectory(trim($storagePrefix, '/'));
    }

    /**
     * Copy every object under $fromPrefix into $toPrefix on the same disk.
     * Used by promote — the preview's R2 artifacts get duplicated to a
     * fresh parent-owned prefix so tearing down the preview later doesn't
     * delete the production artifacts out from under the live deployment.
     */
    public function copyPrefix(string $fromPrefix, string $toPrefix, ?string $diskName = null): int
    {
        $disk = $this->disk($diskName);
        $from = trim($fromPrefix, '/');
        $to = trim($toPrefix, '/');

        if ($from === '' || $to === '' || $from === $to) {
            return 0;
        }

        $relatives = [];
        foreach ($disk->allFiles($from) as $sourceKey) {
            $relative = ltrim(substr((string) $sourceKey, strlen($from)), '/');
            if ($relative !== '') {
                $relatives[] = $relative;
            }
        }

        if (! $disk instanceof AwsS3V3Adapter) {
            foreach ($relatives as $relative) {
                if ($disk->copy($from.'/'.$relative, $to.'/'.$relative) === false) {
                    throw new RuntimeException("Failed to copy artifact {$relative}.");
                }
            }

            return count($relatives);
        }

        $client = $disk->getClient();
        $bucket = (string) $disk->getConfig()['bucket'];
        $failed = $this->runPool($client, (function () use ($relatives, $client, $bucket, $disk, $from, $to) {
            foreach ($relatives as $relative) {
                yield $relative => $client->getCommand('CopyObject', [
                    'Bucket' => $bucket,
                    'Key' => $disk->path($to.'/'.$relative),
                    'CopySource' => $bucket.'/'.S3Client::encodeKey($disk->path($from.'/'.$relative)),
                    'MetadataDirective' => 'COPY',
                    // Everything in a deployment prefix is uploaded public
                    // except the build log (see BuildEdgeSiteJob).
                    'ACL' => $relative === 'build.log' ? 'private' : 'public-read',
                ]);
            }
        })());

        if ($failed !== []) {
            $first = reset($failed);

            throw new RuntimeException(sprintf(
                'Failed to copy %d of %d artifact(s) (first: %s: %s).',
                count($failed), count($relatives), (string) array_key_first($failed), $first['error'],
            ));
        }

        return count($relatives);
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private function localFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[ltrim(str_replace($dir, '', $file->getPathname()), '/\\')] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Single-part MD5 ETags of every object under $fullPrefix, keyed by the
     * path relative to it. Multipart ETags ("…-N") aren't content MD5s, so
     * they're left out and those files re-upload. A listing failure just
     * means nothing is reused.
     *
     * @return array<string, string>
     */
    private function etagsUnder(S3Client $client, string $bucket, string $fullPrefix): array
    {
        $etags = [];
        try {
            foreach ($client->getPaginator('ListObjectsV2', ['Bucket' => $bucket, 'Prefix' => $fullPrefix]) as $page) {
                foreach ($page['Contents'] ?? [] as $object) {
                    $etag = strtolower(trim((string) ($object['ETag'] ?? ''), '"'));
                    if (preg_match('/^[a-f0-9]{32}$/', $etag) === 1) {
                        $etags[substr((string) $object['Key'], strlen($fullPrefix))] = $etag;
                    }
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $etags;
    }

    /**
     * Runs the commands concurrently; returns the failures keyed like the input.
     *
     * @param  iterable<string, CommandInterface>  $commands
     * @return array<string, array{command: string, error: string}>
     */
    private function runPool(S3Client $client, iterable $commands): array
    {
        $failed = [];
        (new CommandPool($client, $commands, [
            'concurrency' => max(1, (int) config('edge.r2.upload_concurrency', 32)),
            'preserve_iterator_keys' => true,
            'rejected' => function ($reason, $key) use (&$failed): void {
                $failed[(string) $key] = [
                    'command' => $reason instanceof AwsException ? $reason->getCommand()->getName() : '',
                    'error' => $reason instanceof AwsException
                        ? ($reason->getAwsErrorCode() ?? $reason->getMessage())
                        : ($reason instanceof \Throwable ? $reason->getMessage() : (string) $reason),
                ];
            },
        ]))->promise()->wait();

        return $failed;
    }

    private function disk(?string $diskName): Filesystem
    {
        $name = $diskName ?? (string) config('edge.disk.name', 'edge_r2');

        return Storage::disk($name);
    }

    private function cacheControlFor(string $path): string
    {
        if ($path === 'index.html' || str_ends_with($path, '/index.html')) {
            return 'public, max-age=0, must-revalidate';
        }
        if ($this->isHashedAsset($path)) {
            return 'public, max-age=31536000, immutable';
        }

        return 'public, max-age=3600';
    }

    /** Mirrors isImmutableAsset() in packages/edge-worker/src/handler.ts. */
    private function isHashedAsset(string $path): bool
    {
        if (preg_match('/\.[a-f0-9]{8,}\./i', $path) === 1
            || preg_match('#(^|/)(_next/static|_astro|_app/immutable)/#', $path) === 1) {
            return true;
        }
        if (preg_match('/[-.]([a-f0-9]{8,}|[A-Za-z0-9_-]{8})\.(?:m?js|css|woff2?|png|jpe?g|webp|avif|svg|gif|ico|wasm|map)$/', $path, $m) !== 1) {
            return false;
        }

        return preg_match('/\d/', $m[1]) === 1
            || (preg_match('/[A-Z]/', substr($m[1], 1)) === 1 && preg_match('/[a-z]/', $m[1]) === 1);
    }

    private function mimeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html' => 'text/html; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            default => 'application/octet-stream',
        };
    }
}
