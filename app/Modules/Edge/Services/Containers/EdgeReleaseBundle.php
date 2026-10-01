<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Containers;

use App\Models\Site;
use Illuminate\Support\Facades\Storage;

/**
 * Release bundles (Faster starts apps; meta.edge.container.release_bundle
 * false turns them off). A deploy no longer pushes a new image: the generated
 * Dockerfile is split into
 *
 *  - a runtime image (the final stage up to `COPY composer.json`, plus its
 *    static tail: ENV, the nginx config, EXPOSE, CMD), built and pushed only
 *    when that text changes, and
 *  - a release: /app from the full build, exported as a tar, gzipped, put in
 *    R2 and fetched by the container at boot (http://release.dply/, served by
 *    the Worker from the RELEASES binding) before the usual start command.
 *
 * Only generated PHP Dockerfiles split; anything else deploys as before.
 *
 * The container snapshots itself after a release's first start
 * (DO_CONTAINER_BASE takeSnapshot), so later wakes skip the download. The
 * newest KEEP releases stay in R2; the app's delete removes the rest.
 * Measured on placehold, 2026-09-30: deploy 2m35s -> ~60s; wake 6.3s -> 4.7s.
 */
final class EdgeReleaseBundle
{
    public const RELEASE_STAGE = 'dply-release';

    /**
     * Where the container unpacks the release before booting (EdgeContainerDockerfile's
     * CMD follows). A release named {deployment}.{vendor}.tar.gz also needs
     * vendor-{vendor}.tar.gz (vendorKey), unpacked first.
     */
    public const FETCH = 'r0=$(cut -d" " -f1 /proc/uptime); '
        .'get() { wget -q -O "$2" "http://release.dply/$1" 2>/dev/null || php -r \'exit(@copy("http://release.dply/".$argv[1], $argv[2]) ? 0 : 1);\' "$1" "$2"; }; '
        // Started from this release's snapshot: /app is already unpacked.
        .'if [ -n "$DPLY_RELEASE" ] && [ "$(cat /app/.dply-release 2>/dev/null)" = "$DPLY_RELEASE" ]; then echo "dply-release: from snapshot"; '
        .'else b=${DPLY_RELEASE##*/}; b=${b%.tar.gz}; v=${b#*.}; ok=1; '
        .'if [ "$v" != "$b" ]; then get "${DPLY_RELEASE%/*}/vendor-$v.tar.gz" /tmp/dply-vendor.tgz && tar -xzf /tmp/dply-vendor.tgz -C /app && rm -f /tmp/dply-vendor.tgz || ok=0; fi; '
        .'if [ "$ok" = 1 ] && get "$DPLY_RELEASE" /tmp/dply-release.tgz && tar -xzf /tmp/dply-release.tgz -C /app && rm -f /tmp/dply-release.tgz; '
        .'then echo "$DPLY_RELEASE" > /app/.dply-release; echo "dply-release: fetched $(cut -d" " -f1 /proc/uptime) started $r0"; '
        .'else echo "dply-release: could not fetch the release"; exit 1; fi; fi; ';

    /**
     * @return array{runtime: string, release: string, hash: string}|null
     */
    public static function split(string $dockerfile): ?array
    {
        $lines = preg_split('/\R/', rtrim($dockerfile)) ?: [];
        $finalFrom = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^FROM\s/i', $line) === 1) {
                $finalFrom = $i;
            }
        }
        if ($finalFrom === null) {
            return null;
        }
        $deps = null;
        for ($i = $finalFrom + 1; $i < count($lines); $i++) {
            if (str_starts_with($lines[$i], 'COPY composer.json')) {
                $deps = $i;
                break;
            }
        }
        $cmd = null;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, 'CMD ["sh", "-c", ')) {
                $cmd = $i;
            }
        }
        if ($deps === null || $cmd === null) {
            return null;
        }
        $boot = json_decode(substr($lines[$cmd], 4), true);
        if (! is_array($boot) || count($boot) !== 3) {
            return null;
        }

        // Runtime: the final stage before the app, then what does not depend on it.
        $runtime = array_slice($lines, $finalFrom, $deps - $finalFrom);
        foreach (array_slice($lines, $deps) as $line) {
            if (str_starts_with($line, 'ENV ') || str_starts_with($line, 'RUN printf %s ') || str_starts_with($line, 'EXPOSE ')) {
                $runtime[] = $line;
            }
        }
        $runtime[] = 'CMD '.json_encode(['sh', '-c', self::FETCH.$boot[2]], JSON_UNESCAPED_SLASHES);

        // Release: the full build, then only /app from its final stage.
        $release = $lines;
        if (preg_match('/^FROM\s+\S+\s+AS\s+(\S+)/i', $lines[$finalFrom], $m) === 1) {
            $stage = $m[1];
        } else {
            $stage = 'dply-app';
            $release[$finalFrom] = rtrim($lines[$finalFrom]).' AS '.$stage;
        }
        $release[] = 'FROM scratch AS '.self::RELEASE_STAGE;
        $release[] = 'COPY --from='.$stage.' /app /';

        $runtimeText = implode("\n", $runtime)."\n";

        return [
            'runtime' => $runtimeText,
            'release' => implode("\n", $release)."\n",
            'hash' => substr(hash('sha256', $runtimeText), 0, 16),
        ];
    }

    /** Releases kept per app after a deploy: the live one and a couple to go back to. */
    public const KEEP = 3;

    public static function prefix(Site $site): string
    {
        return 'releases/'.strtolower((string) $site->id).'/';
    }

    /** vendor/ is named by what decides it: composer.lock (or composer.json) and the runtime image. */
    public static function vendorHash(string $checkout, string $runtimeHash): string
    {
        $lock = is_file($checkout.'/composer.lock') ? (string) file_get_contents($checkout.'/composer.lock') : (string) @file_get_contents($checkout.'/composer.json');

        return substr(hash('sha256', $lock."\n".$runtimeHash), 0, 16);
    }

    public static function vendorKey(Site $site, string $vendorHash): string
    {
        return self::prefix($site).'vendor-'.$vendorHash.'.tar.gz';
    }

    /** {deployment}.{vendor}.tar.gz, so the container (FETCH) knows which vendor/ goes with it. */
    public static function appKey(Site $site, string $deploymentId, ?string $vendorHash): string
    {
        return self::prefix($site).$deploymentId.($vendorHash !== null ? '.'.$vendorHash : '').'.tar.gz';
    }

    /**
     * Delete all but the newest KEEP releases (the live one is always the
     * newest), then every vendor/ archive no kept release uses.
     *
     * @return int archives deleted
     */
    public static function prune(Site $site, int $keep = self::KEEP): int
    {
        $disk = Storage::disk((string) config('edge.disk.name', 'edge_r2'));
        $files = collect($disk->files(self::prefix($site)));
        [$vendors, $releases] = $files->partition(fn (string $path): bool => str_starts_with(basename($path), 'vendor-'));
        $releases = $releases->sortByDesc(fn (string $path): int => (int) $disk->lastModified($path))->values();
        $kept = $releases->take($keep);
        $used = $kept->map(fn (string $path): ?string => preg_match('/^[^.]+\.([0-9a-f]{16})\.tar\.gz$/', basename($path), $m) === 1 ? $m[1] : null)->filter()->all();
        $old = [
            ...$releases->slice($keep)->all(),
            ...$vendors->reject(fn (string $path): bool => in_array(substr(basename($path), 7, 16), $used, true))->all(),
        ];
        if ($old !== []) {
            $disk->delete($old);
        }

        return count($old);
    }

    /** The app is gone: every release goes with it. */
    public static function forget(Site $site): void
    {
        Storage::disk((string) config('edge.disk.name', 'edge_r2'))->deleteDirectory(rtrim(self::prefix($site), '/'));
    }
}
