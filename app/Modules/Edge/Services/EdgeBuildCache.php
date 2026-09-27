<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\Site;
use App\Modules\Edge\Jobs\SnapshotEdgeBuildCacheJob;
use App\Modules\Edge\Support\FakeEdgeProvision;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Build cache for Edge sites. Persists per-site directories
 * (`node_modules`, `.next/cache`, framework caches) into the same R2
 * bucket that holds the artifacts, keyed by lockfile + node version
 * + repo_root so a different lock invalidates the cache.
 *
 * Flow per deploy (called from {@see EdgeBuildRunner}):
 *
 *   1. After clone, before Docker: try {@see restore()} — pulls
 *      cache/{site_id}/{cache_key}.tar.gz from R2 and extracts into
 *      the build directory.
 *   2. After a successful Docker build: {@see snapshot()} re-tars the
 *      cache paths and uploads. By default this runs via
 *      {@see SnapshotEdgeBuildCacheJob} after
 *      publish (off the deploy critical path); set
 *      `edge.build.async_cache_snapshot=false` for inline snapshot.
 *   3. {@see prune()} keeps total per-site cache bytes under the cap
 *      via LRU eviction.
 *
 * Cache is best-effort — any failure logs to the build log and the
 * deploy continues with a cold cache. Never blocks a deploy.
 */
class EdgeBuildCache
{
    /** Hard cap on total cache bytes per site before LRU pruning. */
    private const DEFAULT_MAX_BYTES = 500 * 1024 * 1024;

    /** Paths (relative to the build directory) snapshotted into the tar. */
    private const CACHE_PATHS = [
        'node_modules',
        '.next/cache',
        '.nuxt',
        '.astro',
        '.svelte-kit',
        'node_modules/.cache',
        'dist/.cache',
    ];

    public function cacheKey(string $checkout, ?string $repoRoot, string $nodeVersion = '20'): string
    {
        $base = rtrim($checkout, '/');
        $rootSegment = $repoRoot !== null && $repoRoot !== '' ? trim($repoRoot, '/') : '';
        if ($rootSegment !== '') {
            $base = $base.'/'.$rootSegment;
        }

        $parts = [$nodeVersion, $rootSegment];
        foreach (['pnpm-lock.yaml', 'yarn.lock', 'bun.lock', 'bun.lockb', 'package-lock.json'] as $lockfile) {
            $path = $base.'/'.$lockfile;
            if (is_file($path)) {
                $parts[] = $lockfile.':'.hash_file('sha256', $path);
            }
        }
        if (count($parts) <= 2) {
            // No lockfile present — fall back to package.json so we still
            // get a cache, but it invalidates on any version bump.
            $pkg = $base.'/package.json';
            if (is_file($pkg)) {
                $parts[] = 'package.json:'.hash_file('sha256', $pkg);
            }
        }

        // "<env>-<deps>": the env half (node + repo root) is the restore-key
        // prefix — a lockfile change falls back to the newest cache with the
        // same env instead of going fully cold.
        return substr(hash('sha256', $nodeVersion.'|'.$rootSegment), 0, 12)
            .'-'.substr(hash('sha256', implode('|', $parts)), 0, 32);
    }

    /**
     * @return array{ok: bool, restored_bytes: int, message: string}
     */
    public function restore(string $checkout, ?string $repoRoot, string $cacheKey, Site $site, ?string $diskName = null): array
    {
        if (FakeEdgeProvision::enabled()) {
            return ['ok' => false, 'restored_bytes' => 0, 'message' => 'fake-edge: cache restore skipped'];
        }

        $disk = $this->disk($diskName);
        $key = $this->storageKey($site, $cacheKey);
        $restored = 'restored '.$cacheKey;
        if (! $disk->exists($key)) {
            // Like actions/cache restore-keys: the package manager reconciles
            // a near-miss far faster than a cold install.
            $key = $this->newestWithEnv($disk, $site, $cacheKey);
            if ($key === null) {
                return ['ok' => false, 'restored_bytes' => 0, 'message' => 'cache miss for key '.$cacheKey];
            }
            $restored = 'cache miss for key '.$cacheKey.', restored fallback '.basename($key, '.tar.gz');
        }

        $base = $this->extractRoot($checkout, $repoRoot);
        $tmpTar = (string) tempnam(sys_get_temp_dir(), 'dply-edge-cache-');
        try {
            // Streamed — the tarball can be hundreds of MB.
            $in = $disk->readStream($key);
            $out = fopen($tmpTar, 'wb');
            if (! is_resource($in) || $out === false) {
                return ['ok' => false, 'restored_bytes' => 0, 'message' => 'cache download failed for '.basename($key)];
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $restoredBytes = filesize($tmpTar) ?: 0;
            $result = Process::timeout(120)->run([
                'tar', '-xzf', $tmpTar, '-C', $base,
            ]);
            if (! $result->successful()) {
                return ['ok' => false, 'restored_bytes' => 0, 'message' => 'tar extract failed: '.$result->errorOutput()];
            }

            return ['ok' => true, 'restored_bytes' => $restoredBytes, 'message' => $restored];
        } finally {
            @unlink($tmpTar);
        }
    }

    /**
     * @return array{ok: bool, snapshot_bytes: int, message: string}
     */
    public function snapshot(string $checkout, ?string $repoRoot, string $cacheKey, Site $site, ?string $diskName = null): array
    {
        if (FakeEdgeProvision::enabled()) {
            return ['ok' => false, 'snapshot_bytes' => 0, 'message' => 'fake-edge: cache snapshot skipped'];
        }

        $base = $this->extractRoot($checkout, $repoRoot);
        $existing = $this->existingPaths($base);
        if ($existing === []) {
            return ['ok' => false, 'snapshot_bytes' => 0, 'message' => 'no cache paths exist yet'];
        }

        $tmpTar = (string) tempnam(sys_get_temp_dir(), 'dply-edge-cache-snap-');
        try {
            // GNU/BSD tar both accept `-C` + relative paths. Listing
            // each path explicitly avoids tar-ing the entire checkout.
            $args = ['tar', '-czf', $tmpTar, '-C', $base, ...$existing];
            $result = Process::timeout(180)->run($args);
            if (! $result->successful()) {
                return ['ok' => false, 'snapshot_bytes' => 0, 'message' => 'tar create failed: '.$result->errorOutput()];
            }

            $bytes = filesize($tmpTar) ?: 0;
            $disk = $this->disk($diskName);
            $stream = fopen($tmpTar, 'rb');
            try {
                $ok = $disk->writeStream($this->storageKey($site, $cacheKey), $stream, [
                    'visibility' => 'private',
                    'ContentType' => 'application/gzip',
                ]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if ($ok === false) {
                return ['ok' => false, 'snapshot_bytes' => 0, 'message' => 'cache upload failed'];
            }

            return ['ok' => true, 'snapshot_bytes' => $bytes, 'message' => 'snapshotted '.count($existing).' path(s), '.$bytes.' bytes'];
        } finally {
            @unlink($tmpTar);
        }
    }

    /**
     * LRU prune: list cache/{site_id}/, sort by mtime, delete oldest
     * until total bytes fit under $maxBytes. Returns the number of
     * keys deleted. Best-effort — failures are silent.
     */
    public function prune(Site $site, ?string $diskName = null, int $maxBytes = self::DEFAULT_MAX_BYTES): int
    {
        if (FakeEdgeProvision::enabled()) {
            return 0;
        }

        $disk = $this->disk($diskName);
        $prefix = 'cache/'.$site->id;
        $files = $disk->allFiles($prefix);
        if ($files === []) {
            return 0;
        }

        $entries = [];
        $total = 0;
        foreach ($files as $path) {
            $size = (int) $disk->size($path);
            $mtime = (int) $disk->lastModified($path);
            $entries[] = ['path' => $path, 'size' => $size, 'mtime' => $mtime];
            $total += $size;
        }
        if ($total <= $maxBytes) {
            return 0;
        }

        usort($entries, fn ($a, $b) => $a['mtime'] <=> $b['mtime']);
        $deleted = 0;
        while ($total > $maxBytes && $entries !== []) {
            $oldest = array_shift($entries);
            try {
                $disk->delete($oldest['path']);
                $total -= $oldest['size'];
                $deleted++;
            } catch (\Throwable) {
                // Continue — partial prune is better than none.
            }
        }

        return $deleted;
    }

    /**
     * @return list<string>
     */
    private function existingPaths(string $base): array
    {
        $existing = [];
        foreach (self::CACHE_PATHS as $path) {
            $full = rtrim($base, '/').'/'.$path;
            if (is_dir($full)) {
                $existing[] = $path;
            }
        }

        return array_values(array_unique($existing));
    }

    private function extractRoot(string $checkout, ?string $repoRoot): string
    {
        $base = rtrim($checkout, '/');
        if ($repoRoot !== null && $repoRoot !== '') {
            $candidate = $base.'/'.trim($repoRoot, '/');
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return $base;
    }

    /**
     * Newest cache tarball for this site whose key shares $cacheKey's env
     * prefix. Scoped to cache/{site_id}/, so never another site's (or org's).
     */
    private function newestWithEnv(Filesystem $disk, Site $site, string $cacheKey): ?string
    {
        $env = strstr($cacheKey, '-', true);
        if ($env === false) {
            return null;
        }

        $newest = null;
        $newestAt = -1;
        foreach ($disk->listContents('cache/'.$site->id, false) as $item) {
            if ($item->isFile() && str_starts_with(basename($item->path()), $env.'-') && ($item->lastModified() ?? 0) > $newestAt) {
                $newest = $item->path();
                $newestAt = $item->lastModified() ?? 0;
            }
        }

        return $newest;
    }

    private function storageKey(Site $site, string $cacheKey): string
    {
        return 'cache/'.$site->id.'/'.$cacheKey.'.tar.gz';
    }

    private function disk(?string $diskName): Filesystem
    {
        return Storage::disk($diskName ?? (string) config('edge.disk.name', 'edge_r2'));
    }
}
