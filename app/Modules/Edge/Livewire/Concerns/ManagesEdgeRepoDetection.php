<?php

declare(strict_types=1);

namespace App\Modules\Edge\Livewire\Concerns;

use App\Jobs\DetectRepositoryRuntimeJob;
use App\Livewire\Forms\EdgeCreateForm;
use App\Modules\Edge\Services\EdgeMonorepoDetector;
use App\Modules\Edge\Services\RuntimeDetection\FrontendAssetBuild;
use App\Modules\Edge\Support\EdgeSitePackageHeuristics;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesEdgeRepoDetection
{
    public function updatedRepoSource(string $value): void
    {
        if ($value === 'manual') {
            $this->repository_selection = '';
            $this->maybeAutoDetectFromRepository();

            return;
        }

        if ($this->repository_selection !== '') {
            return;
        }

        $this->runDetection('', $this->branch);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseGitHubOwnerName(string $repo): ?array
    {
        if ($repo === '') {
            return null;
        }
        $normalized = EdgeCreateForm::normalizeRepo($repo);
        if (preg_match('#^https?://github\.com/#i', $normalized) === 1) {
            return null; // GitLab/Bitbucket normalize to full URL, skip
        }
        if (str_contains($normalized, '/') && substr_count($normalized, '/') === 1) {
            [$owner, $name] = explode('/', $normalized, 2);
            $owner = trim($owner);
            $name = trim($name);
            if ($owner !== '' && $name !== '') {
                return [$owner, $name];
            }
        }

        return null;
    }

    private function githubHttpClient(): PendingRequest
    {
        $client = Http::baseUrl('https://api.github.com')
            ->acceptJson()
            ->timeout(8)
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);

        $user = auth()->user();
        if ($user !== null) {
            $identity = app(GitIdentityResolver::class)->forUserProvider($user, 'github');
            $token = $identity?->accessToken();
            if (is_string($token) && $token !== '') {
                $client = $client->withToken($token);
            }
        }

        return $client;
    }

    /**
     * @param  list<array{label: string, sha: string, meta: ?string}>  $rows
     * @return list<array{label: string, sha: string, meta: ?string}>
     */
    private function filterResults(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => ($r['sha']) !== ''));
        $search = mb_strtolower(trim($this->refPickerSearch));
        if ($search === '') {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $r) use ($search): bool {
            foreach (['label', 'sha', 'meta'] as $field) {
                $value = mb_strtolower((string) ($r[$field] ?? ''));
                if ($value !== '' && str_contains($value, $search)) {
                    return true;
                }
            }

            return false;
        }));
    }

    public function updatedRepo(): void
    {
        if ($this->repo_source !== 'manual') {
            return;
        }

        // Operators commonly paste a full GitHub URL (browser tab, README badge,
        // `git clone` line). Canonicalize to `owner/name` so the rest of the
        // flow — detection fingerprint, persisted edgeMeta.source.repo, alias
        // hostnames — sees the shape it expects. Also lift a branch hint out
        // of `/tree/<branch>` URLs, but only when the user hasn't typed their
        // own branch yet (default 'main' counts as untouched).
        $raw = trim($this->repo);
        if ($raw !== '') {
            $branchHint = EdgeCreateForm::branchHintFromUrl($raw);
            $normalized = EdgeCreateForm::normalizeRepo($raw);
            if ($normalized !== $raw) {
                $this->repo = $normalized;
            }
            if ($branchHint !== null && ($this->branch === '' || $this->branch === 'main')) {
                $this->branch = $branchHint;
            }

            $this->maybeSeedAppNameFromRepo();
        }

        // Inline validation — push a clear error into the bag so the operator
        // doesn't have to wait until Deploy to learn "the top one seems
        // usless" isn't a valid repo. Empty strings are skipped here so
        // the field doesn't yell at someone who's just clicked into it.
        if (trim($this->repo) !== '') {
            $this->resetErrorBag('repo');
            if (! $this->isPlausibleRepoRef($this->repo)) {
                $this->addError('repo', __('Enter :format or a full GitHub / GitLab / Bitbucket URL.', ['format' => 'owner/name']));

                return;
            }
        }

        $this->maybeAutoDetectFromRepository();
    }

    /**
     * Auto-set $form->name from the current $repo value when the name
     * field is empty. Centralized so the connected-account picker,
     * manual-typing path, and query-prefill path all benefit. Sticky
     * once the operator types anything.
     */
    private function maybeSeedAppNameFromRepo(): void
    {
        if (trim($this->form->name) !== '') {
            return;
        }
        $repoName = $this->extractRepoNameForApp($this->repo);
        if ($repoName !== '') {
            $this->form->name = $repoName;
        }
    }

    /**
     * Pull the repo name (no owner, no host) out of a normalized repo
     * reference. Used to seed the app name field. Returns '' when the
     * shape doesn't yield an obvious name.
     */
    private function extractRepoNameForApp(string $value): string
    {
        $value = trim($value, " \t\n\r/");
        if ($value === '') {
            return '';
        }

        // Full URL → keep just the last path segment
        if (preg_match('#^https?://[^/]+/(.+)$#i', $value, $m) === 1) {
            $value = $m[1];
        } elseif (preg_match('#^git@[^:]+:(.+)$#i', $value, $m) === 1) {
            $value = $m[1];
        }

        // Strip a trailing `.git`
        $value = preg_replace('/\.git$/i', '', $value) ?? $value;

        $parts = array_filter(explode('/', trim($value, '/')));
        $name = (string) end($parts);

        return strtolower(trim($name));
    }

    /**
     * Loose plausibility check — passes the common shapes (owner/name,
     * normalized full URLs for the three known hosts, SSH git@ URLs) and
     * rejects free text like "the top one seems useless". Backend
     * validation still runs at deploy time; this is a pre-flight nudge.
     */
    private function isPlausibleRepoRef(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        // owner/name shorthand (what GitHub URLs normalize to)
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $value) === 1) {
            return true;
        }

        // Full URL on a known host (GitLab / Bitbucket normalize here)
        if (preg_match('#^https?://(github\.com|gitlab\.com|bitbucket\.org)/[^\s]+$#i', $value) === 1) {
            return true;
        }

        // SSH form (rare on the create form but accept for paste-from-clone)
        if (preg_match('#^git@(github\.com|gitlab\.com|bitbucket\.org):[^\s]+$#i', $value) === 1) {
            return true;
        }

        return false;
    }

    public function updatedBranch(): void
    {
        if ($this->repo_source === 'connected') {
            if (trim($this->repo) !== '') {
                $this->detectFromRepository();
            }

            return;
        }

        $this->maybeAutoDetectFromRepository();
    }

    public function updatedSourceControlAccountId(): void
    {
        $this->repository_selection = '';
        $this->loadRepositoriesForSelectedAccount();
    }

    public function updatedRepositorySelection(string $value): void
    {
        if ($value === '') {
            return;
        }

        $match = collect($this->availableRepositories)->firstWhere('url', $value);
        if (! is_array($match)) {
            return;
        }

        $this->repo = EdgeCreateForm::normalizeRepo((string) $match['url']);
        $this->branch = $match['branch'] !== ''
            ? (string) $match['branch']
            : 'main';

        $this->maybeSeedAppNameFromRepo();
        $this->detectFromRepository();
    }

    public function detectFromRepository(): void
    {
        $this->runDetection($this->normalizeToCloneUrl($this->repo), $this->branch);
        $this->detectMonorepoFromRepository();
    }

    /**
     * Override the trait's blocking detection with a queue-backed flow.
     * Repos like withastro/starlight take >30s to clone+inspect and
     * crash PHP's request wall-clock. We dispatch a DetectRepositoryRuntimeJob,
     * cache the result keyed by (url, branch), and let the view poll
     * pollRuntimeDetection until it lands. Same-key repeats reuse the
     * cached result — no re-cloning when the operator just retypes.
     */

    /**
     * Sync GitHub-only fast path. Returns true when detection
     * completes inline (cache + state populated, no job dispatched).
     * Returns false to signal the caller to fall back to the queued
     * clone-based detection.
     *
     * For Node repos we skip the heavy RepositoryRuntimePlanComposer
     * entirely — the composer runs every runtime detector (Node,
     * Python, Ruby, Go, PHP, Static) against the on-disk tree, which
     * is overkill when we already know we have package.json. A
     * straight package.json parse + framework heuristic returns a
     * usable plan in <50ms instead of >1s.
     */
    private function tryFastGitHubDetection(string $url, string $branch, string $cacheKey): bool
    {
        // Only handle GitHub URLs (or owner/name shorthand which we
        // normalize to a GitHub host). GitLab/Bitbucket fall back.
        //
        // Lazy `+?` + explicit boundary so repo names containing dots
        // (tailwindcss.com, react.dev, nodejs.org) match correctly;
        // the previous `[^/.]+` stopped at the first dot and made the
        // owner/repo pair wrong → 404 → fall-through to the slow path.
        if (preg_match('~github\.com[/:]([^/]+)/([^/\s?#]+?)(?:\.git)?(?:[/?#]|$)~i', $url, $m) !== 1) {
            return false;
        }
        $owner = $m[1];
        $repo = rtrim($m[2], '/');

        try {
            // Single Contents API call for package.json — that's all the
            // Node fast path needs. ~200ms round-trip end-to-end.
            $repoRoot = trim((string) ($this->form->repo_root ?? ''));

            // PHP and Ruby apps usually also ship a package.json (Vite,
            // esbuild), so their manifests are checked first — otherwise a
            // Laravel repo reads as a static Vite site.
            $planArray = $this->synthesizeContainerPlan($owner, $repo, $branch, $repoRoot, $url);
            if ($planArray === null) {
                $packageJson = $this->fetchPackageJsonFromGitHub($owner, $repo, $branch, $repoRoot);
                if ($packageJson === null) {
                    return false;
                }
                $planArray = $this->synthesizeNodePlan($packageJson, $url, $branch);
            }
            if ($planArray === null) {
                // package.json present but framework unknown — defer to
                // the clone-based composer which has broader heuristics.
                return false;
            }

            Cache::put($cacheKey, [
                'state' => 'done',
                'plan' => $planArray,
                'source' => 'fast-path',
            ], now()->addHours(24));

            $this->detectedPlan = $planArray;
            $this->runtimeDetectionPending = false;
            $this->applyDetectedRuntimePrefills();

            return true;
        } catch (\Throwable) {
            // Network blip, GitHub rate-limit, malformed response — let
            // the queued clone path handle it.
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPackageJsonFromGitHub(string $owner, string $repo, string $branch, string $subdir = ''): ?array
    {
        $raw = $this->fetchGitHubFile($owner, $repo, $branch, 'package.json', $subdir);
        $parsed = $raw !== null ? json_decode($raw, true) : null;

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * PHP (composer.json) or Ruby (Gemfile) app → a container plan, without
     * cloning. Null when neither manifest exists.
     *
     * @return array<string, mixed>|null
     */
    private function synthesizeContainerPlan(string $owner, string $repo, string $branch, string $subdir, string $url): ?array
    {
        $plan = static fn (string $runtime, string $framework, string $source, ?string $build, ?string $start): array => [
            'url' => $url,
            'branch' => $branch,
            'runtime' => $runtime,
            'version' => null,
            'framework' => $framework,
            'build_command' => $build,
            'start_command' => $start,
            'app_port' => 8080,
            'output_dir' => null,
            'confidence' => 'high',
            'sources' => [$source],
            'reasons' => ['Fast-path GitHub API detection', "Found {$source} — runs as a container"],
            'warnings' => [],
            'has_manifest' => true,
            'processes' => [],
            'not_a_site' => false,
        ];

        $composer = $this->fetchGitHubFile($owner, $repo, $branch, 'composer.json', $subdir);
        if ($composer !== null) {
            $json = json_decode($composer, true);
            $require = is_array($json['require'] ?? null) ? $json['require'] : [];
            $framework = match (true) {
                isset($require['laravel/framework']) => 'laravel',
                isset($require['symfony/framework-bundle']) => 'symfony',
                default => 'php',
            };

            $build = 'composer install --no-dev --optimize-autoloader';
            $packageRaw = $this->fetchGitHubFile($owner, $repo, $branch, 'package.json', $subdir);
            $package = is_string($packageRaw) ? json_decode($packageRaw, true) : null;
            $assets = FrontendAssetBuild::commandForPackage(
                is_array($package) ? $package : [],
                null,
                is_array($json) ? $json : null,
            );
            if ($assets !== null) {
                $build .= ' && '.$assets;
            }

            return $plan('php', $framework, 'composer.json', $build, null);
        }

        $gemfile = $this->fetchGitHubFile($owner, $repo, $branch, 'Gemfile', $subdir);
        if ($gemfile !== null) {
            $framework = match (true) {
                preg_match('/^\s*gem\s+["\']rails["\']/m', $gemfile) === 1 => 'rails',
                preg_match('/^\s*gem\s+["\']sinatra["\']/m', $gemfile) === 1 => 'sinatra',
                default => 'ruby',
            };

            return $plan('ruby', $framework, 'Gemfile', 'bundle install', 'bundle exec puma');
        }

        return null;
    }

    /**
     * Raw text of one repo file: raw.githubusercontent.com for public repos,
     * the Contents API with the user's GitHub token for private ones.
     */
    private function fetchGitHubFile(string $owner, string $repo, string $branch, string $path, string $subdir = ''): ?string
    {
        $relative = trim(str_replace('\\', '/', $subdir), '/');
        $filePath = $relative !== '' ? $relative.'/'.$path : $path;

        // Path 1: raw.githubusercontent.com — direct file content, no
        // API metadata wrapping, no base64 decode, no per-IP rate limit
        // worth worrying about. Works for any PUBLIC repo. Typically
        // 80-150ms.
        try {
            $rawUrl = sprintf(
                'https://raw.githubusercontent.com/%s/%s/%s/%s',
                $owner,
                $repo,
                $branch,
                $filePath,
            );
            $rawResponse = Http::timeout(5)->get($rawUrl);
            if ($rawResponse->successful()) {
                return $rawResponse->body();
            }
        } catch (\Throwable) {
            // Fall through to authenticated API path.
        }

        // Path 2: authenticated Contents API for private repos. Slightly
        // slower (base64 wrap + 1 extra hop) but the only way to read
        // private repos when the user has a linked GitHub identity.
        $user = auth()->user();
        $token = null;
        if ($user !== null) {
            try {
                $identity = app(GitIdentityResolver::class)->forUserProvider($user, 'github');
                $accessToken = $identity?->accessToken();
                if (is_string($accessToken) && $accessToken !== '') {
                    $token = $accessToken;
                }
            } catch (\Throwable) {
                // Anonymous fall-through.
            }
        }

        if ($token === null) {
            // No auth and raw failed — private repo we can't read, or
            // 404 — defer to the queued clone path.
            return null;
        }

        $apiResponse = Http::baseUrl('https://api.github.com')
            ->acceptJson()
            ->timeout(6)
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->withToken($token)
            ->get("/repos/{$owner}/{$repo}/contents/{$filePath}", ['ref' => $branch]);

        if (! $apiResponse->successful()) {
            return null;
        }

        $body = $apiResponse->json();
        if (! is_array($body) || ! isset($body['content'])) {
            return null;
        }

        $decoded = base64_decode((string) preg_replace('/\s+/', '', (string) $body['content']), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Heuristic plan synthesis from a parsed package.json. Recognizes
     * the most common JS frameworks operators deploy to Edge and maps
     * each to a sensible default build command + output dir. Returns
     * null when we can't classify (caller falls back to clone).
     *
     * @param  array<string, mixed>  $pkg
     * @return array<string, mixed>|null
     */
    private function synthesizeNodePlan(array $pkg, string $url, string $branch): ?array
    {
        $deps = array_merge(
            is_array($pkg['dependencies'] ?? null) ? $pkg['dependencies'] : [],
            is_array($pkg['devDependencies'] ?? null) ? $pkg['devDependencies'] : [],
        );

        // Framework lookup: dep name → [framework, default build, default output]
        // Keel before hono/vite — kits declare both `@shaferllc/keel` and `hono`.
        // Node HTTP servers run as containers; a Nest app is a server even
        // when it also pulls in a frontend toolchain.
        if (isset($deps['@nestjs/core'])) {
            return $this->nodeServerPlan('nest', $pkg, $url, $branch);
        }

        $frameworkMap = [
            '@shaferllc/keel' => ['keel', 'npm run css:build --if-present', 'public'],
            'astro' => ['astro', 'npm run build', 'dist'],
            'next' => ['next', 'npm run build', 'out'],
            'nuxt' => ['nuxt', 'npm run generate', '.output/public'],
            'gatsby' => ['gatsby', 'npm run build', 'public'],
            '@sveltejs/kit' => ['sveltekit', 'npm run build', 'build'],
            'vite' => ['vite', 'npm run build', 'dist'],
            '@11ty/eleventy' => ['eleventy', 'npm run build', '_site'],
            'vitepress' => ['vitepress', 'npm run docs:build', 'docs/.vitepress/dist'],
            '@docusaurus/core' => ['docusaurus', 'npm run build', 'build'],
            'hono' => ['hono', 'npm run build', 'dist'],
        ];

        $framework = null;
        $build = (string) ($pkg['scripts']['build'] ?? '');
        $output = null;
        foreach ($frameworkMap as $dep => [$f, $b, $o]) {
            if (isset($deps[$dep])) {
                $framework = $f;
                $build = $build !== '' ? 'npm run build' : $b;
                $output = $o;
                break;
            }
        }

        // Fallback Node plan when no framework matched but a build
        // script exists — assume vite/webpack-style dist output.
        if ($framework === null) {
            foreach (['express', 'fastify', 'koa'] as $server) {
                if (isset($deps[$server])) {
                    return $this->nodeServerPlan($server, $pkg, $url, $branch);
                }
            }
            if (! isset($pkg['scripts']['build'])) {
                return null;
            }
            $framework = 'node_generic';
            $build = 'npm run build';
            $output = 'dist';
        }

        $engines = (string) ($pkg['engines']['node'] ?? '');
        $version = $engines !== '' ? $engines : null;
        $notASite = EdgeSitePackageHeuristics::looksLikeNonDeployablePackageRoot($pkg);

        return [
            'url' => $url,
            'branch' => $branch,
            'runtime' => 'node',
            'version' => $version,
            'framework' => $framework,
            'build_command' => $build,
            'start_command' => null,
            'app_port' => null,
            'output_dir' => $output,
            'confidence' => $notASite ? 'low' : 'high',
            'sources' => ['package.json'],
            'reasons' => $notASite
                ? ['Fast-path GitHub API detection', 'Root looks like a framework/monorepo package, not a single site']
                : ['Fast-path GitHub API detection'],
            'warnings' => $notASite
                ? ['Pick an app package directory before deploying to Edge.']
                : [],
            'has_manifest' => true,
            'processes' => [],
            'not_a_site' => $notASite,
        ];
    }

    /**
     * @param  array<string, mixed>  $pkg
     * @return array<string, mixed>
     */
    private function nodeServerPlan(string $framework, array $pkg, string $url, string $branch): array
    {
        return [
            'url' => $url,
            'branch' => $branch,
            'runtime' => 'node',
            'version' => ($pkg['engines']['node'] ?? null) ?: null,
            'framework' => $framework,
            'build_command' => isset($pkg['scripts']['build']) ? 'npm run build' : null,
            'start_command' => isset($pkg['scripts']['start']) ? 'npm start' : null,
            'app_port' => 8080,
            'output_dir' => null,
            'confidence' => 'high',
            'sources' => ['package.json'],
            'reasons' => ['Fast-path GitHub API detection', "Found {$framework} — runs as a container"],
            'warnings' => [],
            'has_manifest' => true,
            'processes' => [],
            'not_a_site' => false,
        ];
    }

    /**
     * Detection job died / never wrote a result and the cache entry
     * has been sitting in queued/running for longer than the job's
     * timeout. Pop it so the next dispatch attempt gets a fresh run.
     *
     * @param  array<string, mixed>  $cached
     */
    private function isStaleDetectionEntry(array $cached): bool
    {
        $dispatchedAt = $cached['dispatched_at'] ?? null;
        if (! is_string($dispatchedAt) || $dispatchedAt === '') {
            return false;
        }
        try {
            $ts = Carbon::parse($dispatchedAt);
        } catch (\Throwable) {
            return true;
        }

        return $ts->diffInSeconds(now()) > 200;
    }

    /**
     * View-side wire:poll target while the runtime detection job is in
     * flight. Reads the cache key the matching dispatch wrote to and
     * flips state back to "done" once the worker stores a result.
     */
    public function pollRuntimeDetection(): void
    {
        if (! $this->runtimeDetectionPending || $this->runtimeDetectionKey === '') {
            return;
        }

        $cached = Cache::get($this->runtimeDetectionKey);

        // Cache entry vanished entirely — TTL expired without a result
        // landing. Treat as a failure so the operator isn't stuck on a
        // forever spinner.
        if (! is_array($cached)) {
            $this->detectedPlan = [
                'error' => __('Detection timed out. Click "Detect runtime" to retry.'),
            ];
            $this->runtimeDetectionPending = false;
            $this->applyDetectedRuntimePrefills();

            return;
        }

        $state = $cached['state'] ?? null;

        // Job dispatched too long ago and still queued/running — worker
        // most likely died (Horizon stopped, OOM, …). Surface as failed
        // so the UI unblocks and the next Detect runtime click re-dispatches.
        if (in_array($state, ['queued', 'running'], true) && $this->isStaleDetectionEntry($cached)) {
            $this->detectedPlan = [
                'error' => __('Detection took longer than expected and may have died. Click "Detect runtime" to try again.'),
            ];
            $this->runtimeDetectionPending = false;
            $this->applyDetectedRuntimePrefills();

            return;
        }

        if (! in_array($state, ['done', 'failed'], true)) {
            return; // still in flight, keep polling
        }

        $this->detectedPlan = (array) ($cached['plan'] ?? []);
        $this->runtimeDetectionPending = false;
        $this->applyDetectedRuntimePrefills();
    }

    public function updatedFormRepoRoot(): void
    {
        $this->repoRootTouched = true;
        // Re-run fast detection against the chosen package so Astro
        // examples (etc.) clear the framework-monorepo not_a_site gate.
        $this->lastDetectionFingerprint = '';
        $this->detectFromRepository();
    }

    private function detectMonorepoFromRepository(): void
    {
        $url = $this->normalizeToCloneUrl($this->repo);
        $branch = trim($this->branch) !== '' ? trim($this->branch) : 'main';
        if ($url === '') {
            $this->monorepoDetected = false;
            $this->monorepoPackages = [];
            $this->monorepoMarkers = [];

            return;
        }

        try {
            $result = app(EdgeMonorepoDetector::class)->inspectUrl($url, $branch);
            $this->monorepoDetected = (bool) $result['is_monorepo'];
            $this->monorepoPackages = $result['packages'];
            $this->monorepoMarkers = $result['markers'];

            if ($this->monorepoDetected && ! $this->repoRootTouched && count($this->monorepoPackages) === 1) {
                $this->form->repo_root = (string) $this->monorepoPackages[0]['path'];
            }
        } catch (\Throwable) {
            $this->monorepoDetected = false;
            $this->monorepoPackages = [];
            $this->monorepoMarkers = [];
        }
    }

    private function maybeAutoDetectFromRepository(): void
    {
        $repo = trim($this->repo);
        $branch = trim($this->branch) !== '' ? trim($this->branch) : 'main';

        if ($repo === '') {
            $this->lastDetectionFingerprint = '';
            $this->runDetection('', $branch);

            return;
        }

        if (! $this->repoLooksComplete($repo) || $branch === '') {
            return;
        }

        $repoRoot = trim((string) ($this->form->repo_root ?? ''));
        $fingerprint = $this->normalizeToCloneUrl($repo).'|'.$branch.'|'.$repoRoot;
        if ($fingerprint === $this->lastDetectionFingerprint) {
            return;
        }

        $this->lastDetectionFingerprint = $fingerprint;
        $this->detectFromRepository();
    }

    private function repoLooksComplete(string $repo): bool
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $repo) === 1 || str_starts_with($repo, 'git@')) {
            return true;
        }

        $normalized = trim($repo, '/');
        if (! str_contains($normalized, '/')) {
            return false;
        }

        [$owner, $name] = array_pad(explode('/', $normalized, 2), 2, '');

        return trim($owner) !== '' && trim($name) !== '';
    }

    private function loadRepositoriesForSelectedAccount(): void
    {
        if ($this->source_control_account_id === '') {
            $this->availableRepositories = [];

            return;
        }

        $account = auth()->user() !== null
            ? app(GitIdentityResolver::class)->forId(auth()->user(), $this->source_control_account_id)
            : null;
        $this->availableRepositories = $account
            ? app(SourceControlRepositoryBrowser::class)->repositoriesForAccount($account)
            : [];
    }
}
