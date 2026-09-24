<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Modules\Edge\Support\EdgeRepoRoot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Wraps `git clone` with two protections operators care about:
 *
 *   • Mirror cache — one `--mirror` clone per repo URL, refreshed with
 *     `git fetch` instead of re-downloaded from scratch. The build then
 *     clones from the local mirror, so a flaky GitHub round-trip doesn't
 *     kill an otherwise-good deploy and a no-op rebuild is essentially
 *     network-free.
 *
 *   • Retry — every network-touching git call gets up to 3 tries with a
 *     short backoff, so a single TCP RTO timeout no longer fails the build.
 *
 * Falls back to direct clone (no cache, no retry-on-success) if the
 * mirror layer itself blows up, so caching can never be the reason a
 * build fails.
 */
final class EdgeRepoCloner
{
    private const NETWORK_RETRIES = 3;

    private const RETRY_BACKOFF_SECONDS = 2;

    /** Network ops can legitimately take a while on big repos. */
    private const NETWORK_TIMEOUT_SECONDS = 300;

    /**
     * Populating a mirror fetches every branch and tag, so it legitimately
     * takes longer than a single-branch clone — 300s isn't enough for a repo
     * with deep history on a contended link.
     */
    private const MIRROR_CLONE_TIMEOUT_SECONDS = 900;

    /** Set for the duration of a clone() call so progress can stream out. */
    private $onLine = null;

    /**
     * Record a line and push it to the live build log immediately.
     *
     * @param  list<string>  $log
     *
     * @param-out list<string> $log
     */
    private function note(array &$log, string $line): void
    {
        $log[] = $line;
        if ($this->onLine !== null && trim($line) !== '') {
            ($this->onLine)($line);
        }
    }

    /**
     * Clone `$repoUrl` into `$checkout`, checking out `$branch` (and
     * optionally `$commitOverride`). When `$sparseRoot` is set (monorepo
     * package path), use a shallow + cone sparse-checkout so we don't
     * materialize every workspace package on disk.
     *
     * @return list<string>
     */
    public function clone(
        string $repoUrl,
        string $branch,
        string $checkout,
        ?string $commitOverride = null,
        ?string $sparseRoot = null,
        ?callable $onLine = null,
    ): array {
        // Streamed as it happens: buffering the whole clone means a
        // multi-minute mirror population shows the operator nothing but
        // "[dply:step] clone", which reads as a hang.
        $this->onLine = $onLine;
        $log = [];
        $sparseRoot = EdgeRepoRoot::normalize($sparseRoot);

        // Always start from an empty checkout. Retries (and mirror→direct
        // fallback) otherwise hit "destination path already exists".
        $this->ensureEmptyCheckout($checkout);

        if ($this->cacheEnabled()) {
            try {
                return $this->cloneViaMirror($repoUrl, $branch, $checkout, $commitOverride, $sparseRoot, $log);
            } catch (\Throwable $e) {
                $log[] = '[git-cache] mirror path failed, falling back to direct clone: '.$e->getMessage();
                $this->ensureEmptyCheckout($checkout);
            }
        }

        return $this->cloneDirect($repoUrl, $branch, $checkout, $commitOverride, $sparseRoot, $log);
    }

    /**
     * @param  list<string>  $log
     * @return list<string>
     */
    private function cloneViaMirror(
        string $repoUrl,
        string $branch,
        string $checkout,
        ?string $commitOverride,
        string $sparseRoot,
        array $log,
    ): array {
        $mirror = $this->mirrorPath($repoUrl);
        $cacheRoot = dirname($mirror);
        if (! is_dir($cacheRoot) && ! mkdir($cacheRoot, 0755, true) && ! is_dir($cacheRoot)) {
            throw new RuntimeException("Could not create git cache root: {$cacheRoot}");
        }

        // Serialize concurrent builds for the same repo so two workers
        // don't fight over the mirror dir.
        $lockHandle = $this->acquireLock($cacheRoot.'/.'.basename($mirror).'.lock');

        try {
            if ($this->mirrorIsUsable($mirror, $repoUrl)) {
                $this->note($log, "[git-cache] Refreshing mirror at {$mirror}");
                $this->runWithRetry(['git', '--git-dir='.$mirror, 'fetch', '--prune', '--tags', 'origin'], $log);
            } else {
                // Populating a mirror pulls every branch and tag, so it costs
                // far more than the --branch fallback clone. If it just timed
                // out, don't pay that again on the next build — go straight to
                // the direct clone until the cooldown lapses.
                if (Cache::has(self::mirrorCooldownKey($repoUrl))) {
                    throw new RuntimeException('mirror population failed recently; using direct clone');
                }

                if (is_dir($mirror)) {
                    $this->note($log, "[git-cache] Discarding unusable mirror at {$mirror}");
                    File::deleteDirectory($mirror);
                }
                $this->note($log, "[git-cache] Cloning mirror {$repoUrl} → {$mirror}");

                try {
                    $this->runWithRetry(['git', 'clone', '--mirror', $repoUrl, $mirror], $log, null, self::MIRROR_CLONE_TIMEOUT_SECONDS);
                } catch (\Throwable $e) {
                    Cache::put(self::mirrorCooldownKey($repoUrl), true, now()->addMinutes(30));
                    if (is_dir($mirror)) {
                        File::deleteDirectory($mirror);
                    }

                    throw $e;
                }
            }

            // The actual build checkout — clone from the local mirror.
            // git clones from a local path use hardlinks, so the working
            // tree is created near-instantly and `--depth` is a no-op
            // (git warns about it). Keep history; it costs almost nothing.
            $this->ensureEmptyCheckout($checkout);
            $this->note($log, "Cloning from local mirror @ {$branch}");
            if ($commitOverride !== null) {
                $this->runWithRetry(['git', 'clone', $mirror, $checkout], $log, $checkout);
            } else {
                $this->runWithRetry(['git', 'clone', '--branch', $branch, $mirror, $checkout], $log, $checkout);
            }

            if ($commitOverride !== null) {
                $checkoutResult = Process::timeout(60)->path($checkout)->run(['git', 'checkout', $commitOverride]);
                $log[] = trim($checkoutResult->output().$checkoutResult->errorOutput());
                if (! $checkoutResult->successful()) {
                    throw new RuntimeException('Build failed: commit "'.$commitOverride.'" not found in repository.');
                }
            }

            $this->applySparseCheckout($checkout, $sparseRoot, $log);
        } finally {
            $this->releaseLock($lockHandle);
        }

        return array_values(array_filter($log, static fn ($line) => $line !== ''));
    }

    /**
     * A mirror is only reusable if it can actually be fetched from.
     *
     * An interrupted `git clone --mirror` leaves objects/ behind with no
     * remote configured, and the old `is_dir(objects)` test then chose the
     * refresh path forever: every build failed with "'origin' does not appear
     * to be a git repository", burned the fetch timeout, and left another
     * tmp_pack. Nothing ever repaired it.
     */
    /**
     * Fetch exactly one commit, no history.
     *
     * `git clone` cannot take a SHA, so init + fetch --depth 1 <sha> is the
     * only way to avoid pulling the whole repo for a commit-pinned deploy.
     * Returns false when the remote refuses (allowAnySHA1InWant off) so the
     * caller can fall back.
     *
     * @param  list<string>  $log
     *
     * @param-out list<string> $log
     */
    private function shallowFetchCommit(string $repoUrl, string $sha, string $checkout, array &$log): bool
    {
        $this->ensureEmptyCheckout($checkout);
        File::ensureDirectoryExists($checkout);

        foreach ([
            ['git', 'init', '-q', $checkout],
            ['git', '-C', $checkout, 'remote', 'add', 'origin', $repoUrl],
        ] as $command) {
            if (! Process::timeout(60)->run($command)->successful()) {
                return false;
            }
        }

        $fetch = Process::timeout(self::NETWORK_TIMEOUT_SECONDS)
            ->run(['git', '-C', $checkout, 'fetch', '--depth', '1', 'origin', $sha]);
        $this->note($log, trim($fetch->output().$fetch->errorOutput()));
        if (! $fetch->successful()) {
            return false;
        }

        $checkoutResult = Process::timeout(60)->run(['git', '-C', $checkout, 'checkout', '-q', 'FETCH_HEAD']);
        $this->note($log, trim($checkoutResult->output().$checkoutResult->errorOutput()));

        return $checkoutResult->successful();
    }

    private static function mirrorCooldownKey(string $repoUrl): string
    {
        return 'edge-git-mirror-cooldown:'.hash('sha256', $repoUrl);
    }

    private function mirrorIsUsable(string $mirror, string $repoUrl): bool
    {
        if (! is_dir($mirror.'/objects')) {
            return false;
        }

        $configured = Process::timeout(15)->run(
            ['git', '--git-dir='.$mirror, 'config', '--get', 'remote.origin.url'],
        );

        return $configured->successful() && trim($configured->output()) === trim($repoUrl);
    }

    /**
     * @param  list<string>  $log
     * @return list<string>
     */
    private function cloneDirect(
        string $repoUrl,
        string $branch,
        string $checkout,
        ?string $commitOverride,
        string $sparseRoot,
        array $log,
    ): array {
        $this->ensureEmptyCheckout($checkout);
        $useSparse = $sparseRoot !== '' && (bool) config('edge.build.sparse_checkout', true);

        if ($commitOverride !== null) {
            // Rollback/redeploy-at-commit is exactly when a full-history clone
            // hurts most. GitHub allows fetching an arbitrary SHA, so ask for
            // just that commit; fall back to the full clone if a remote
            // refuses (allowAnySHA1InWant off).
            $this->note($log, "Fetching {$repoUrl} @ {$commitOverride} (shallow)");
            if (! $this->shallowFetchCommit($repoUrl, $commitOverride, $checkout, $log)) {
                $this->note($log, 'Shallow commit fetch unavailable — falling back to full clone');
                $this->ensureEmptyCheckout($checkout);
                $this->runWithRetry(['git', 'clone', $repoUrl, $checkout], $log, $checkout);
                $result = Process::timeout(60)->path($checkout)->run(['git', 'checkout', $commitOverride]);
                $this->note($log, trim($result->output().$result->errorOutput()));
                if (! $result->successful()) {
                    throw new RuntimeException('Build failed: commit "'.$commitOverride.'" not found in repository.');
                }
            }
            $this->applySparseCheckout($checkout, $sparseRoot, $log);
        } elseif ($useSparse) {
            $log[] = "Cloning {$repoUrl} @ {$branch} (shallow + sparse: {$sparseRoot})";
            $this->runWithRetry([
                'git', 'clone',
                '--depth', '1',
                '--filter=blob:none',
                '--sparse',
                '--branch', $branch,
                $repoUrl,
                $checkout,
            ], $log, $checkout);
            $this->applySparseCheckout($checkout, $sparseRoot, $log);
        } else {
            $log[] = "Cloning {$repoUrl} @ {$branch}";
            $this->runWithRetry(['git', 'clone', '--depth', '1', '--branch', $branch, $repoUrl, $checkout], $log, $checkout);
        }

        return array_values(array_filter($log, static fn ($line) => $line !== ''));
    }

    /**
     * Cone sparse-checkout keeps root files (lockfiles, workspace yaml)
     * plus the selected package tree. Library monorepos that set
     * preferWorkspacePackages (e.g. withastro/astro examples) are installed
     * via leaf isolation in EdgeBuildRunner — sparse stays on the leaf only.
     * App monorepos that need local workspace packages also sparse-include
     * those package roots so filtered installs can link them.
     *
     * @param  list<string>  $log
     */
    private function applySparseCheckout(string $checkout, string $sparseRoot, array &$log): void
    {
        if ($sparseRoot === '' || ! (bool) config('edge.build.sparse_checkout', true)) {
            return;
        }

        $paths = $this->sparseCheckoutPaths($checkout, $sparseRoot);
        $log[] = '[git] Sparse-checkout cone: '.implode(' ', $paths);
        $init = Process::timeout(60)->path($checkout)->run(['git', 'sparse-checkout', 'init', '--cone']);
        $log[] = trim($init->output().$init->errorOutput());
        if (! $init->successful()) {
            throw new RuntimeException('git sparse-checkout init failed: '.$init->errorOutput());
        }

        $set = Process::timeout(120)->path($checkout)->run(['git', 'sparse-checkout', 'set', ...$paths]);
        $log[] = trim($set->output().$set->errorOutput());
        if (! $set->successful()) {
            throw new RuntimeException('git sparse-checkout set failed: '.$set->errorOutput());
        }
    }

    /**
     * @return list<string>
     */
    private function sparseCheckoutPaths(string $checkout, string $sparseRoot): array
    {
        // preferWorkspacePackages leaves resolve published registry deps
        // (EdgeBuildRunner leaf isolation) — do not materialize packages/.
        if ($this->prefersWorkspacePackages($checkout)) {
            return [$sparseRoot];
        }

        $paths = [$sparseRoot];
        foreach ($this->workspaceSparseRoots($checkout) as $root) {
            if ($root === $sparseRoot || str_starts_with($sparseRoot, $root.'/')) {
                continue;
            }
            $paths[] = $root;
        }

        return array_values(array_unique($paths));
    }

    private function prefersWorkspacePackages(string $checkout): bool
    {
        foreach (['pnpm-workspace.yaml', 'pnpm-workspace.yml'] as $file) {
            $path = $checkout.'/'.$file;
            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            if (preg_match('/^\s*preferWorkspacePackages:\s*true\s*$/m', $contents) === 1) {
                return true;
            }
            if (preg_match('/^\s*linkWorkspacePackages:\s*true\s*$/m', $contents) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Top-level dirs from pnpm-workspace.yaml / package.json workspaces that
     * must be present for workspace-protocol filtered installs.
     *
     * @return list<string>
     */
    private function workspaceSparseRoots(string $checkout): array
    {
        $roots = [];
        $hasWorkspaceManifest = false;

        foreach (['pnpm-workspace.yaml', 'pnpm-workspace.yml'] as $file) {
            $path = $checkout.'/'.$file;
            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }
            $hasWorkspaceManifest = true;
            $contents = (string) file_get_contents($path);
            if (preg_match('/(?m)^packages:\s*\n((?:[ \t]+.+\n?)*)/', $contents, $block) !== 1) {
                continue;
            }
            if (preg_match_all('/^\s*-\s*[\'"]?(?:\.\/)?([A-Za-z0-9_.-]+)/m', $block[1], $matches) > 0) {
                foreach ($matches[1] as $segment) {
                    if (in_array($segment, ['node_modules', '.', '..'], true)) {
                        continue;
                    }
                    $roots[] = $segment;
                }
            }
        }

        $pkgPath = $checkout.'/package.json';
        if (is_file($pkgPath) && is_readable($pkgPath)) {
            $decoded = json_decode((string) file_get_contents($pkgPath), true);
            $workspaces = $decoded['workspaces'] ?? null;
            if (is_array($workspaces)) {
                $hasWorkspaceManifest = true;
                $patterns = isset($workspaces['packages']) && is_array($workspaces['packages'])
                    ? $workspaces['packages']
                    : $workspaces;
                foreach ($patterns as $pattern) {
                    if (! is_string($pattern) || $pattern === '' || str_starts_with($pattern, '!')) {
                        continue;
                    }
                    $segment = explode('/', ltrim($pattern, './'), 2)[0];
                    $segment = trim($segment, '*');
                    if ($segment !== '' && ! in_array($segment, ['node_modules', '.', '..'], true)) {
                        $roots[] = $segment;
                    }
                }
            }
        }

        if ($hasWorkspaceManifest) {
            foreach (['packages', 'apps'] as $fallback) {
                $roots[] = $fallback;
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * Run a git command with bounded retry. Each failure logs a line so
     * operators can see WHY a retry kicked in (network blip vs. real auth
     * failure, etc.). Throws after the final attempt fails.
     *
     * When `$cleanPathOnRetry` is set (clone destination), wipe it between
     * attempts so a partial clone doesn't poison the next try with
     * "destination path already exists".
     *
     * @param  list<string>  $command
     * @param  list<string>  $log
     */
    private function runWithRetry(array $command, array &$log, ?string $cleanPathOnRetry = null, ?int $timeoutSeconds = null): void
    {
        $attempt = 0;
        $lastError = '';

        while ($attempt < self::NETWORK_RETRIES) {
            $attempt++;
            $result = Process::timeout($timeoutSeconds ?? self::NETWORK_TIMEOUT_SECONDS)->run($command);
            $this->note($log, trim($result->output().$result->errorOutput()));
            if ($result->successful()) {
                return;
            }

            $lastError = $result->errorOutput() !== '' ? $result->errorOutput() : $result->output();
            if ($attempt < self::NETWORK_RETRIES) {
                if ($cleanPathOnRetry !== null) {
                    $this->ensureEmptyCheckout($cleanPathOnRetry);
                }
                $log[] = sprintf('[git-cache] attempt %d/%d failed — retrying in %ds', $attempt, self::NETWORK_RETRIES, self::RETRY_BACKOFF_SECONDS);
                sleep(self::RETRY_BACKOFF_SECONDS);
            }
        }

        throw new RuntimeException('Git clone failed after '.self::NETWORK_RETRIES.' attempts: '.$lastError);
    }

    /**
     * Re-stats the path instead of reusing an earlier `is_dir()` result. Each
     * removal attempt below changes the filesystem, and the `rm -rf` and docker
     * passes run outside PHP, so PHP's stat cache would otherwise report the
     * directory as still present.
     *
     * @phpstan-impure
     */
    private function checkoutExists(string $checkout): bool
    {
        clearstatcache(true, $checkout);

        return is_dir($checkout);
    }

    private function ensureEmptyCheckout(string $checkout): void
    {
        if (! $this->checkoutExists($checkout)) {
            return;
        }

        File::deleteDirectory($checkout);
        if (! $this->checkoutExists($checkout)) {
            return;
        }

        // Docker builds can leave root-owned files in the mount; Laravel's
        // recursive delete (and host rm as www-data) then can't clear the
        // tree and the next clone dies with "destination path already exists".
        Process::timeout(120)->run(['rm', '-rf', '--', $checkout]);
        if (! $this->checkoutExists($checkout)) {
            return;
        }

        $parent = dirname($checkout);
        $base = basename($checkout);
        $dockerRm = Process::timeout(180)->run([
            'docker', 'run', '--rm',
            '-v', $parent.':/wipe',
            'alpine:3.20',
            'rm', '-rf', '--', '/wipe/'.$base,
        ]);
        if ($this->checkoutExists($checkout)) {
            throw new RuntimeException(
                'Could not clear checkout directory '.$checkout
                .($dockerRm->errorOutput() !== '' ? ': '.$dockerRm->errorOutput() : '')
            );
        }
    }

    private function mirrorPath(string $repoUrl): string
    {
        $cacheRoot = (string) config(
            'edge.build.git_cache_dir',
            storage_path('app/edge-git-cache'),
        );

        // Hash the URL so we don't have to sanitize host/path/auth bits
        // into a directory name. Suffix with `.git` so a stray `git status`
        // on the parent doesn't try to treat the cache as a working tree.
        return rtrim($cacheRoot, '/').'/'.hash('sha256', strtolower(trim($repoUrl))).'.git';
    }

    private function cacheEnabled(): bool
    {
        return (bool) config('edge.build.git_cache_enabled', true);
    }

    /**
     * @return resource|null
     */
    private function acquireLock(string $path)
    {
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return null;
        }
        if (! flock($handle, LOCK_EX)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * @param  resource|null  $handle
     */
    private function releaseLock($handle): void
    {
        if ($handle === null) {
            return;
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
