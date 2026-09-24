<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDeployment;
use App\Modules\Edge\Services\Config\EdgeRepoConfigLinter;
use App\Modules\Edge\Services\Config\EdgeRepoConfigLoader;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\Ssr\EdgeSsrFrameworkRegistry;
use App\Modules\Edge\Support\EdgeBuildDockerBootstrap;
use App\Modules\Edge\Support\EdgeLiveBuildLog;
use App\Modules\Edge\Support\EdgeLogCopy;
use App\Modules\Edge\Support\EdgeRepoRoot;
use App\Modules\Edge\Support\FakeEdgeProvision;
use App\Services\DeployContract\DeployContractPolicyLoader;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class EdgeBuildRunner
{
    /** Deployment currently being built (for Redis live-log mirroring). */
    private ?string $activeDeploymentId = null;

    /** Static / SSG sites — output is a directory of files served from R2. */
    public const MODE_STATIC = 'static';

    /** Hybrid sites — same static output, plus a configured origin proxy. */
    public const MODE_HYBRID = 'hybrid';

    /**
     * SSR sites — Next.js + @opennextjs/cloudflare produces both a static
     * assets directory AND a Worker script that ships to a per-deployment
     * dispatch namespace. See {@see EdgeSsrBundleUploader}.
     */
    public const MODE_SSR = 'ssr';

    /**
     * PHP / Rails on Cloudflare Containers: the build deploys an image and a
     * fronting Worker into the dispatch namespace ({@see EdgeContainerDeployer}).
     */
    public const MODE_CONTAINER = 'container';

    /** Cap per-script source bytes to avoid blowing past CF's 10 MB Worker limit. */
    private const SSR_SCRIPT_MAX_BYTES = 9 * 1024 * 1024;

    /**
     * @param  array<string, mixed>  $env
     * @return array{
     *     artifact_dir: string,
     *     build_log: string,
     *     git_commit: ?string,
     *     git_commit_subject?: ?string,
     *     git_commit_author?: ?string,
     *     git_commit_at?: ?string,
     *     ssr_modules?: array<string, string>,
     *     ssr_entry_module?: string,
     *     middleware_modules?: array<string, string>,
     *     middleware_entry_module?: string,
     *     middleware_source_path?: string
     * }
     */
    /**
     * Root directory that per-deploy build workdirs live under.
     *
     * Must be a path the Docker daemon is allowed to share — the checkout is
     * bind-mounted into the build image, and an unshared path (e.g. /var/tmp on
     * macOS) mounts as an EMPTY directory with no error. Also the single source
     * of truth for {@see PublishEdgeDeploymentJob::cleanupLocalArtifact()}'s
     * containment check, which is why it is not inlined at the call sites.
     */
    public static function buildRoot(): string
    {
        $configured = trim((string) config('edge.build.work_root'));

        return $configured !== '' ? $configured : sys_get_temp_dir();
    }

    public function build(
        EdgeDeployment $deployment,
        string $repoUrl,
        string $branch,
        string $buildCommand,
        string $outputDir,
        array $env = [],
        ?string $commitOverride = null,
        string $runtimeMode = self::MODE_STATIC,
        ?string $repoRoot = null,
        ?int $timeoutSeconds = null,
    ): array {
        $workRoot = rtrim(self::buildRoot(), '/').'/dply-edge-build-'.$deployment->id;
        File::ensureDirectoryExists($workRoot);
        $checkout = $workRoot.'/src';
        // Preserve the original checkout root — EdgeRepoRoot may re-point
        // $checkout at a monorepo subdir, and finally must still wipe the
        // whole tree so retries don't see a leftover src/.
        $checkoutRoot = $checkout;
        $artifactDir = $workRoot.'/out';
        $buildLog = $workRoot.'/build.log';
        $preserveCheckoutForCache = false;
        $normalizedRepoRoot = EdgeRepoRoot::normalize($repoRoot);
        $header = '=== dply Edge build '.$deployment->id." ===\n";
        File::put($buildLog, $header);

        // Expose the in-flight log path for same-host tails, and mirror
        // every append into Redis so the Build Journey UI on the web tier
        // can stream output when builds run on a separate worker box.
        $this->activeDeploymentId = (string) $deployment->id;
        EdgeLiveBuildLog::clear($this->activeDeploymentId);
        EdgeLiveBuildLog::append($this->activeDeploymentId, $header);

        $existingMeta = is_array($deployment->meta) ? $deployment->meta : [];
        $deployment->update([
            'meta' => array_merge($existingMeta, ['local_build_log_path' => $buildLog]),
        ]);

        try {
            if (FakeEdgeProvision::enabled()) {
                File::ensureDirectoryExists($artifactDir);
                File::put($artifactDir.'/index.html', '<!doctype html><html><body><h1>dply Edge fake build</h1></body></html>');
                $this->appendBuildLog($buildLog, "Fake edge build — skipped git clone and Docker.\n");
                $fakeCommit = $commitOverride !== null
                    ? strtolower($commitOverride)
                    : substr(hash('sha1', $deployment->id), 0, 40);

                $result = [
                    'artifact_dir' => $artifactDir,
                    'build_log' => $buildLog,
                    'git_commit' => $fakeCommit,
                ];

                if ($runtimeMode === self::MODE_CONTAINER) {
                    $result['container'] = ['script_name' => EdgeContainerDeployer::scriptName($deployment->site), 'stack' => 'fake', 'port' => 8080, 'queues' => []];
                }

                if ($runtimeMode === self::MODE_SSR) {
                    // Stand-in worker module so the fake-edge path covers
                    // the SSR upload code (publisher reads ssr_modules
                    // verbatim and ships it to the dispatch namespace).
                    $result['ssr_entry_module'] = 'worker.js';
                    $result['ssr_modules'] = [
                        'worker.js' => "export default {\n  async fetch(request) {\n    return new Response('dply Edge fake SSR build', { headers: { 'content-type': 'text/plain' } });\n  },\n};\n",
                    ];
                }

                return $result;
            }

            $this->assertDockerAvailable($buildLog);

            // Step marker for the live BuildJourney UI — splits the
            // streamed log into per-step sections so the operator sees
            // clone output under "Cloning repository" and build output
            // under "Installing & building".
            $this->appendBuildLog($buildLog, "[dply:step] clone\n");

            // Delegated to EdgeRepoCloner so we get a per-repo mirror cache
            // (skips re-downloading the whole history on repeat builds) and
            // retry-on-transient-failure (TCP timeouts no longer kill the
            // build outright).
            // Streamed, not collected: a first-time mirror population can run
            // for minutes, and the operator needs to see it working.
            $streamed = [];
            $cloneLog = app(EdgeRepoCloner::class)->clone(
                $repoUrl,
                $branch,
                $checkout,
                $commitOverride,
                $normalizedRepoRoot !== '' ? $normalizedRepoRoot : null,
                function (string $line) use ($buildLog, &$streamed): void {
                    $this->appendBuildLog($buildLog, $line."\n");
                    $streamed[$line] = true;
                },
            );
            // Whatever the cloner recorded without streaming (fallback path).
            foreach ($cloneLog as $line) {
                if (! isset($streamed[$line])) {
                    $this->appendBuildLog($buildLog, $line."\n");
                }
            }

            $resolvedCommit = $this->resolveHead($checkout);
            $commitDetails = $this->resolveHeadDetails($checkout);

            $checkout = EdgeRepoRoot::applyToCheckout(
                $checkout,
                $normalizedRepoRoot !== '' ? $normalizedRepoRoot : $repoRoot,
                fn (string $line) => $this->appendBuildLog($buildLog, $line."\n"),
            );

            // Read dply.yaml (if present) from the checkout root before
            // running the build so build overrides + redirects/headers
            // ship in the same deploy. Snapshot persists on the
            // deployment row so the worker payload can read it later.
            $repoConfig = app(EdgeRepoConfigLoader::class)->loadFromDirectory($checkout);
            $lint = app(EdgeRepoConfigLinter::class)->lint($repoConfig);
            $this->appendBuildLog($buildLog, 'Config lint: '.($lint['ok'] ? 'ok' : 'FAILED')."\n");
            foreach ($lint['warnings'] as $warning) {
                $this->appendBuildLog($buildLog, '[dply.yaml] '.$warning."\n");
            }
            foreach ($lint['errors'] as $error) {
                $this->appendBuildLog($buildLog, '[dply.yaml] ERROR: '.$error."\n");
            }
            if (! $lint['ok']) {
                throw new RuntimeException(
                    'dply config lint failed: '.implode('; ', $lint['errors']),
                );
            }

            if ($repoConfig !== null) {
                $this->appendBuildLog($buildLog, sprintf(
                    "Loaded %s — build:%s redirects:%d rewrites:%d headers:%d\n",
                    $repoConfig->sourcePath,
                    $repoConfig->build === [] ? ' (defaults)' : ' '.json_encode($repoConfig->build, JSON_THROW_ON_ERROR),
                    count($repoConfig->redirects),
                    count($repoConfig->rewrites),
                    count($repoConfig->headers),
                ));

                // Wrangler config discovery — pull bindings out of an
                // existing wrangler.toml / wrangler.jsonc in the repo so
                // users with a working Cloudflare Workers project don't
                // have to also write dply.yaml. dply.yaml entries take
                // precedence (explicit > implicit) when both declare the
                // same binding name.
                $wranglerBindings = app(WranglerBindingsExtractor::class)->extract($checkout);
                $repoArr = $repoConfig->toArray();
                $contract = app(DeployContractPolicyLoader::class)->loadFromDirectory($checkout);
                if ($contract !== null) {
                    $repoArr['contract'] = $contract;
                    $this->appendBuildLog($buildLog, "[dply-contract] Loaded promote requirements from repo.\n");
                }
                $yamlBindings = $repoArr['bindings'];

                // Merge dply.yaml `bindings:` + wrangler.toml discoveries.
                // Both are co-equal declarative sources; on conflict
                // wrangler.toml wins (it's the CF-native format and
                // typically more current for Workers projects).
                $merged = $yamlBindings;
                foreach ($wranglerBindings as $kind => $entries) {
                    if ($entries === []) {
                        continue;
                    }
                    $existing = is_array($merged[$kind] ?? null) ? $merged[$kind] : [];
                    foreach ($entries as $bindingName => $value) {
                        $existing[$bindingName] = $value; // wrangler wins
                    }
                    $merged[$kind] = $existing;
                }

                if ($merged !== []) {
                    $repoArr['bindings'] = $merged;
                }

                foreach ($yamlBindings as $kind => $entries) {
                    if (! is_array($entries)) {
                        continue;
                    }
                    foreach ($entries as $bindingName => $value) {
                        $this->appendBuildLog($buildLog, "[bindings] dply.yaml: {$kind}.{$bindingName} → '{$value}'\n");
                    }
                }
                foreach ($wranglerBindings as $kind => $entries) {
                    foreach ($entries as $bindingName => $value) {
                        $this->appendBuildLog($buildLog, "[bindings] wrangler.toml: {$kind}.{$bindingName} → '{$value}'\n");
                    }
                }

                // bindings.dply — resolve sibling dply-resource refs now so the
                // operator sees each one's status in the build log. Injection
                // happens later at upload (EdgeRepoBindingTranslator) with a
                // fresh resolve; this pass is validation + visibility only, and
                // never persists a connection secret into repo_config.
                $dplyRefs = is_array($merged['dply'] ?? null) ? $merged['dply'] : [];
                // $deployment->site, not $site: $site is not assigned until the
                // build-cache section far below, so this read saw an undefined
                // variable (null) and the whole block silently never ran.
                $dplySite = $deployment->site;
                if ($dplyRefs !== [] && $dplySite !== null) {
                    foreach (app(EdgeDplyResourceResolver::class)->resolve($dplySite, $dplyRefs) as $r) {
                        $label = match ($r['status']) {
                            'public' => 'bound as secret',
                            'private' => 'via origin',
                            default => 'unresolved',
                        };
                        $suffix = $r['warning'] !== null ? ' — '.$r['warning'] : '';
                        $this->appendBuildLog($buildLog, "[bindings] dply: {$r['env_name']} → {$r['ref']} ({$label}){$suffix}\n");
                    }
                } elseif ($dplyRefs !== []) {
                    $this->appendBuildLog($buildLog, "[bindings] dply: refs declared but this build has no site context — skipped.\n");
                }

                // Resolve `error_pages.html_404_path` / `html_500_path` /
                // `maintenance.html_path` to inline HTML so downstream
                // consumers (host map publisher) don't need filesystem
                // access. Skip + warn when the file is missing.
                foreach (['error_pages', 'maintenance'] as $section) {
                    foreach (['html_404_path' => 'html_404', 'html_500_path' => 'html_500', 'html_path' => 'html'] as $pathKey => $htmlKey) {
                        if (! isset($repoArr[$section][$pathKey])) {
                            continue;
                        }
                        $rel = (string) $repoArr[$section][$pathKey];
                        $abs = $checkout.'/'.ltrim($rel, '/');
                        if (! is_file($abs)) {
                            $this->appendBuildLog($buildLog, "[dply.yaml] {$section}.{$pathKey}: '{$rel}' not found — skipped.\n");

                            continue;
                        }
                        $body = (string) file_get_contents($abs);
                        if (strlen($body) > 200000) {
                            $body = substr($body, 0, 200000);
                            $this->appendBuildLog($buildLog, "[dply.yaml] {$section}.{$pathKey}: truncated to 200000 bytes.\n");
                        }
                        $repoArr[$section][$htmlKey] = $body;
                        unset($repoArr[$section][$pathKey]);
                        $this->appendBuildLog($buildLog, "[dply.yaml] Resolved {$section}.{$pathKey} → {$section}.{$htmlKey} (".strlen($body)." bytes)\n");
                    }
                }

                $deployment->update(['repo_config' => $repoArr]);
            } else {
                // No dply.yaml at all — still pick up wrangler bindings
                // and persist a minimal repo_config so the bundle
                // uploaders can wire them onto the worker script.
                $wranglerBindings = app(WranglerBindingsExtractor::class)->extract($checkout);
                if ($wranglerBindings !== []) {
                    $deployment->update([
                        'repo_config' => [
                            'source_path' => 'wrangler.toml',
                            'bindings' => $wranglerBindings,
                        ],
                    ]);
                    foreach ($wranglerBindings as $kind => $entries) {
                        foreach ($entries as $bindingName => $value) {
                            $this->appendBuildLog($buildLog, "[bindings] Discovered wrangler.toml: {$kind}.{$bindingName} → '{$value}'\n");
                        }
                    }
                }
            }

            if ($repoConfig !== null) {
                if (isset($repoConfig->build['root'])) {
                    $candidate = $checkout.'/'.trim($repoConfig->build['root'], '/');
                    if (is_dir($candidate)) {
                        $checkout = $candidate;
                        $this->appendBuildLog($buildLog, 'Using build.root from dply.yaml: '.$repoConfig->build['root']."\n");
                    } else {
                        $this->appendBuildLog($buildLog, '[dply.yaml] build.root '.$repoConfig->build['root'].' not found; falling back to repo root.'."\n");
                    }
                }

                if (isset($repoConfig->build['command']) && $repoConfig->build['command'] !== '') {
                    $buildCommand = $repoConfig->build['command'];
                }
                if (isset($repoConfig->build['output']) && $repoConfig->build['output'] !== '') {
                    $outputDir = $repoConfig->build['output'];
                }

                // build.env_files: load each repo-relative dotenv file
                // and merge into $env. Dashboard-supplied env wins on
                // conflict (it's already in $env when we get here, and
                // we only set keys that aren't present).
                foreach ($repoConfig->envFiles as $relative) {
                    $path = $checkout.'/'.$relative;
                    if (! is_file($path)) {
                        $this->appendBuildLog($buildLog, '[dply.yaml] build.env_files: '.$relative." not found in checkout — skipping.\n");

                        continue;
                    }
                    $loaded = $this->parseDotenvFile((string) file_get_contents($path));
                    $added = 0;
                    foreach ($loaded as $k => $v) {
                        if (array_key_exists($k, $env)) {
                            // Dashboard / production-scope env wins.
                            continue;
                        }
                        $env[$k] = $v;
                        $added++;
                    }
                    $this->appendBuildLog($buildLog, sprintf("[dply.yaml] build.env_files: %s → %d new key(s)\n", $relative, $added));
                }

                // env.public — safe-to-commit values declared in
                // dply.yaml. Dashboard-supplied env still wins on
                // conflict (operators may override per-environment).
                $envPublic = is_array($repoConfig->env['public'] ?? null) ? $repoConfig->env['public'] : [];
                $addedPublic = 0;
                foreach ($envPublic as $k => $v) {
                    if (array_key_exists($k, $env)) {
                        continue; // dashboard wins
                    }
                    $env[$k] = $v;
                    $addedPublic++;
                }
                if ($addedPublic > 0) {
                    $this->appendBuildLog($buildLog, "[dply.yaml] env.public: {$addedPublic} new key(s) merged into build env.\n");
                }

                // env.secret — names the repo promises will exist at
                // runtime. Validate against dashboard storage; missing
                // names get a warning but never fail the build.
                $envSecretNames = is_array($repoConfig->env['secret'] ?? null) ? $repoConfig->env['secret'] : [];
                if ($envSecretNames !== []) {
                    $dashKnown = array_flip(array_keys($env));
                    $missing = [];
                    foreach ($envSecretNames as $name) {
                        if (! isset($dashKnown[$name])) {
                            $missing[] = $name;
                        }
                    }
                    if ($missing !== []) {
                        $this->appendBuildLog($buildLog, '[dply.yaml] env.secret: missing dashboard values for '.implode(', ', $missing).". Set them in Edge → Environment before the next deploy.\n");
                    } else {
                        $this->appendBuildLog($buildLog, '[dply.yaml] env.secret: all '.count($envSecretNames).' name(s) present in dashboard storage.'."\n");
                    }
                }
            }

            // Container mode: no Node build or static artifact — wrangler
            // builds the image and deploys it behind the site's Worker.
            if ($runtimeMode === self::MODE_CONTAINER) {
                $this->appendBuildLog($buildLog, "[dply:step] build\n");
                $container = app(EdgeContainerDeployer::class)->deploy(
                    $deployment->site,
                    $deployment,
                    $checkout,
                    $workRoot,
                    $env,
                    fn (string $line) => $this->appendBuildLog($buildLog, $line),
                    $timeoutSeconds,
                );
                File::ensureDirectoryExists($artifactDir);
                $this->appendBuildLog($buildLog, "Container deployed as {$container['script_name']}.\n");

                return [
                    'artifact_dir' => $artifactDir,
                    'build_log' => $buildLog,
                    'git_commit' => $resolvedCommit,
                    'git_commit_subject' => $commitDetails['subject'] ?? null,
                    'git_commit_author' => $commitDetails['author'] ?? null,
                    'git_commit_at' => $commitDetails['committed_at'] ?? null,
                    'container' => $container,
                ];
            }

            // SSR mode: detect which framework adapter the repo uses
            // and dispatch the build accordingly. Profile registry lives
            // in app/Services/Edge/Ssr/ — adding Astro / SvelteKit /
            // Remix / Next is a one-entry change there.
            $ssrProfile = null;
            if ($runtimeMode === self::MODE_SSR) {
                if (! is_file($checkout.'/package.json')) {
                    throw new RuntimeException('SSR builds require a package.json declaring a supported framework + edge adapter.');
                }
                $packageJson = json_decode((string) file_get_contents($checkout.'/package.json'), true);
                $packageJson = is_array($packageJson) ? $packageJson : [];

                $ssrProfile = EdgeSsrFrameworkRegistry::detectInProject($packageJson);
                if ($ssrProfile === null) {
                    throw new RuntimeException(
                        'SSR Edge sites need one of: Keel, Next.js, Astro, SvelteKit, or Remix. '
                        .'Add the framework + its edge adapter to package.json or pick static / hybrid mode.'
                    );
                }
                if (! EdgeSsrFrameworkRegistry::adapterInstalled($ssrProfile, $packageJson)) {
                    throw new RuntimeException(sprintf(
                        '%s needs `%s` in package.json before SSR builds work — install the adapter and redeploy.',
                        $ssrProfile->label,
                        $ssrProfile->adapterDependency,
                    ));
                }

                $install = $this->detectInstallCommand($checkout) ?? 'npm install';
                if ($ssrProfile->buildCommandOverride !== null) {
                    $buildCommand = $install.' && '.$ssrProfile->buildCommandOverride;
                } else {
                    // Honor the user's build_command for adapters where
                    // they own the build (Astro / SvelteKit / Remix —
                    // they run `astro build` / `vite build` / `remix
                    // vite:build` themselves). Just ensure dependencies
                    // get installed first.
                    $needsInstall = ! preg_match('/\b(npm|yarn|pnpm|bun)\s+(ci|install)\b/i', $buildCommand);
                    if ($needsInstall) {
                        $buildCommand = $install.' && '.$buildCommand;
                    }
                }
                $outputDir = $ssrProfile->assetsPath;
                $this->appendBuildLog($buildLog, sprintf("SSR mode (%s) — adapter detected, build = %s\n", $ssrProfile->label, $buildCommand));
            }

            // Build cache restore — best-effort. For workspace monorepos
            // key + restore from the git root (lockfile + node_modules
            // live there after filtered installs).
            $site = $deployment->site;
            $cacheKey = null;
            $cacheTarget = ($normalizedRepoRoot !== '' && $this->isWorkspaceRoot($checkoutRoot))
                ? $checkoutRoot
                : $checkout;
            if ($site !== null) {
                try {
                    // Key off the package checkout (package.json / local lock);
                    // restore into the workspace root when filtered installs
                    // place node_modules there.
                    $cacheKey = app(EdgeBuildCache::class)->cacheKey($checkout, null);
                    $restoreResult = app(EdgeBuildCache::class)->restore($cacheTarget, null, $cacheKey, $site);
                    $this->appendBuildLog(
                        $buildLog,
                        '[cache] '.$restoreResult['message'].' (key '.$cacheKey.')'."\n",
                    );
                } catch (\Throwable $e) {
                    $this->appendBuildLog($buildLog, '[cache] restore error: '.$e->getMessage()."\n");
                }
            }

            // Pick the Node image from the repo's own version hints
            // (engines.node / .nvmrc / .node-version / packageManager). Falls
            // back to the env-configured default when nothing is declared, so
            // operators can still pin globally via DPLY_EDGE_BUILD_IMAGE if
            // they want a hard override.
            $detection = app(NodeVersionDetector::class)->detect($checkout);
            if ($detection['detected']) {
                $dockerImage = $detection['image'];
                $this->appendBuildLog($buildLog, sprintf(
                    "[node] Detected Node %d from %s (\"%s\") — using %s\n",
                    $detection['major'],
                    $detection['source'],
                    $detection['raw'] ?? '',
                    $dockerImage,
                ));
            } else {
                $dockerImage = (string) config('edge.build.docker_image', $detection['image']);
                $this->appendBuildLog($buildLog, sprintf(
                    "[node] No version hints in repo — using default %s\n",
                    $dockerImage,
                ));
            }

            // Step marker — everything after this line is the install +
            // build phase, rendered under "Installing & building" in the
            // BuildJourney UI.
            $this->appendBuildLog($buildLog, "[dply:step] build\n");

            $script = $this->composeBuildScript($checkout, $buildCommand, $checkoutRoot, $normalizedRepoRoot);

            // Skip pull when the image is already local (warm workers /
            // repeat deploys). Cold miss still pulls with live progress.
            $this->ensureDockerImage($dockerImage, $buildLog);

            // Mount the git root so workspace lockfiles + filtered installs
            // see the whole monorepo; workdir is the selected package.
            $containerWorkdir = $normalizedRepoRoot !== ''
                ? '/src/'.$normalizedRepoRoot
                : '/src';

            $this->appendBuildLog($buildLog, "Running build in {$dockerImage}: {$script}\n");
            $build = Process::timeout($timeoutSeconds ?? (int) config('edge.build.timeout_seconds', 900))
                ->run(
                    [
                        'docker', 'run', '--rm',
                        '-v', $checkoutRoot.':/src',
                        '-w', $containerWorkdir,
                        ...$this->packageStoreVolumeFlags(),
                        ...$this->dockerEnvFlags($env),
                        $dockerImage,
                        'bash', '-lc', $script,
                    ],
                    // Stream stdout/stderr into the build log as it arrives so
                    // the live tail in the BuildJourney UI shows pnpm install
                    // / vite build chatter in real time, not in a final dump.
                    fn (string $type, string $chunk) => $this->appendBuildLog($buildLog, $chunk),
                );
            if (! $build->successful()) {
                throw new RuntimeException($this->summarizeBuildFailure($build, $buildLog));
            }

            // Middleware bundling — runs in a separate docker
            // invocation against the same image so user-authored
            // middleware.ts can ship to the dispatch namespace without
            // requiring node + esbuild on the build host. Static + hybrid
            // sites only (SSR already owns middleware via OpenNext).
            $middlewareBundle = ['bundled' => false];
            if ($runtimeMode !== self::MODE_SSR) {
                try {
                    $middlewareBundle = app(EdgeMiddlewareBundler::class)->bundle(
                        $checkout,
                        $dockerImage,
                        fn (string $line) => $this->appendBuildLog($buildLog, $line."\n"),
                    );
                } catch (\Throwable $e) {
                    $this->appendBuildLog($buildLog, '[middleware] bundler error: '.$e->getMessage()."\n");
                    $middlewareBundle = ['bundled' => false];
                }
            }

            // Cache snapshot — async by default (after publish) so tar+R2
            // upload doesn't sit on the deploy critical path. Inline when
            // async_cache_snapshot is disabled.
            $cacheAsync = null;
            if ($site !== null && $cacheKey !== null) {
                if ((bool) config('edge.build.async_cache_snapshot', true)) {
                    $preserveCheckoutForCache = true;
                    $cacheAsync = [
                        'site_id' => (string) $site->id,
                        'checkout' => $cacheTarget,
                        'cache_key' => $cacheKey,
                        'checkout_root' => $checkoutRoot,
                    ];
                    $this->appendBuildLog($buildLog, "[cache] Snapshot deferred until after publish.\n");
                } else {
                    try {
                        $snapResult = app(EdgeBuildCache::class)->snapshot($cacheTarget, null, $cacheKey, $site);
                        $this->appendBuildLog($buildLog, '[cache] '.$snapResult['message']."\n");
                        if ($snapResult['ok']) {
                            $deleted = app(EdgeBuildCache::class)->prune($site);
                            if ($deleted > 0) {
                                $this->appendBuildLog($buildLog, '[cache] pruned '.$deleted.' old cache entr(ies).'."\n");
                            }
                        }
                    } catch (\Throwable $e) {
                        $this->appendBuildLog($buildLog, '[cache] snapshot error: '.$e->getMessage()."\n");
                    }
                }
            }

            $resolvedOutput = $checkout.'/'.trim($outputDir, '/');
            if (! is_dir($resolvedOutput)) {
                throw new RuntimeException("Build output directory not found: {$outputDir}");
            }

            File::ensureDirectoryExists($artifactDir);
            File::copyDirectory($resolvedOutput, $artifactDir);

            if ($runtimeMode === self::MODE_SSR && $ssrProfile !== null) {
                $workerPath = $checkout.'/'.$ssrProfile->workerPath;
                [$entryModule, $modules] = $this->collectSsrModules($workerPath, $ssrProfile->entryModule, $ssrProfile->label);
                $totalBytes = array_sum(array_map('strlen', $modules));
                $this->assertArtifactSize($artifactDir);
                $this->appendBuildLog($buildLog, sprintf(
                    "Build succeeded — %s assets + worker bundle ready (%d module(s), %d bytes).\n",
                    $ssrProfile->label,
                    count($modules),
                    $totalBytes,
                ));

                $result = [
                    'artifact_dir' => $artifactDir,
                    'build_log' => $buildLog,
                    'git_commit' => $resolvedCommit,
                    'git_commit_subject' => $commitDetails['subject'] ?? null,
                    'git_commit_author' => $commitDetails['author'] ?? null,
                    'git_commit_at' => $commitDetails['committed_at'] ?? null,
                    'ssr_entry_module' => $entryModule,
                    'ssr_modules' => $modules,
                ];
                if ($cacheAsync !== null) {
                    $result['cache_async'] = $cacheAsync;
                }

                return $result;
            }

            $this->assertArtifactContents($artifactDir, $outputDir);
            $this->assertArtifactSize($artifactDir);
            $this->appendBuildLog($buildLog, "Build succeeded — artifacts copied to output.\n");

            $result = [
                'artifact_dir' => $artifactDir,
                'build_log' => $buildLog,
                'git_commit' => $resolvedCommit,
                'git_commit_subject' => $commitDetails['subject'] ?? null,
                'git_commit_author' => $commitDetails['author'] ?? null,
                'git_commit_at' => $commitDetails['committed_at'] ?? null,
            ];

            if ($middlewareBundle['bundled'] === true) {
                $result['middleware_modules'] = $middlewareBundle['modules'];
                $result['middleware_entry_module'] = (string) $middlewareBundle['entry_module'];
                $result['middleware_source_path'] = (string) $middlewareBundle['source_path'];
            }

            if ($cacheAsync !== null) {
                $result['cache_async'] = $cacheAsync;
            }

            return $result;
        } finally {
            if (is_dir($checkoutRoot) && ! $preserveCheckoutForCache) {
                File::deleteDirectory($checkoutRoot);
            }
        }
    }

    private function resolveHead(string $checkout): ?string
    {
        $result = Process::timeout(10)->path($checkout)->run(['git', 'rev-parse', 'HEAD']);
        if (! $result->successful()) {
            return null;
        }

        $sha = trim($result->output());

        return $sha === '' ? null : strtolower($sha);
    }

    /**
     * Capture commit subject + author + iso date for the resolved HEAD so the
     * preview row and deploy history can show what's actually deployed
     * (especially useful for ad-hoc previews from tags / non-main branches
     * where the SHA alone tells the operator nothing). Format uses %x1f
     * (unit separator) so commit messages with newlines/pipes don't trip us.
     *
     * @return array{subject: ?string, author: ?string, committed_at: ?string}
     */
    private function resolveHeadDetails(string $checkout): array
    {
        $result = Process::timeout(10)->path($checkout)->run([
            'git', 'log', '-1', '--pretty=format:%s%x1f%an%x1f%aI',
        ]);
        if (! $result->successful()) {
            return ['subject' => null, 'author' => null, 'committed_at' => null];
        }

        $parts = explode("\x1f", trim($result->output()), 3);

        return [
            'subject' => $parts[0] !== '' ? mb_substr($parts[0], 0, 200) : null,
            'author' => isset($parts[1]) && $parts[1] !== '' ? mb_substr($parts[1], 0, 120) : null,
            'committed_at' => $parts[2] ?? null,
        ];
    }

    private function appendBuildLog(string $path, string $chunk): void
    {
        $chunk = EdgeLogCopy::forCustomer(self::scrubHostPaths($chunk));
        File::append($path, $chunk);
        if ($this->activeDeploymentId !== null && $this->activeDeploymentId !== '') {
            EdgeLiveBuildLog::append($this->activeDeploymentId, $chunk);
        }
    }

    /**
     * Build logs are customer-facing, and git/docker happily print the build
     * host's absolute paths ("Cloning into '/Users/…/edge-builds/…/src'").
     * Those say nothing useful about the customer's build and expose our
     * layout, so collapse them to the path inside the build.
     */
    private static function scrubHostPaths(string $chunk): string
    {
        $roots = array_unique(array_filter([
            rtrim(self::buildRoot(), '/'),
            rtrim(base_path(), '/'),
        ]));

        foreach ($roots as $root) {
            // …/dply-edge-build-<id>/src → src
            $chunk = preg_replace('#'.preg_quote($root, '#').'/dply-edge-build-[A-Za-z0-9]+/?#', '', $chunk) ?? $chunk;
            $chunk = str_replace($root.'/', '', $chunk);
            $chunk = str_replace($root, '', $chunk);
        }

        return $chunk;
    }

    /**
     * Minimal dotenv parser for build.env_files. Honors KEY=value,
     * double-quoted values (\n + \t escapes), single-quoted (literal),
     * # comments, blank lines, and `export KEY=value`. No interpolation.
     *
     * @return array<string, string>
     */
    private function parseDotenvFile(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $eq = strpos($trimmed, '=');
            if ($eq === false || $eq === 0) {
                continue;
            }
            $key = trim(substr($trimmed, 0, $eq));
            if (str_starts_with($key, 'export ')) {
                $key = trim(substr($key, 7));
            }
            if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) !== 1) {
                continue;
            }
            $value = trim(substr($trimmed, $eq + 1));
            if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
                $value = str_replace(['\\n', '\\t', '\\"'], ["\n", "\t", '"'], substr($value, 1, -1));
            } elseif (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
                $value = substr($value, 1, -1);
            } else {
                $hash = strpos($value, ' #');
                if ($hash !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Read the SSR adapter's worker output into the module map
     * {@see EdgeSsrBundleUploader} ships to the dispatch namespace.
     * Accepts either a single bundled file (Next.js / Remix style) or
     * a directory of helper modules + entry (Astro / SvelteKit style).
     *
     * @return array{0: string, 1: array<string, string>} [entryModule, modules]
     */
    private function collectSsrModules(string $workerPath, string $configuredEntry, string $frameworkLabel): array
    {
        if (is_dir($workerPath)) {
            $modules = [];
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($workerPath, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (! in_array($ext, ['js', 'mjs', 'wasm'], true)) {
                    continue;
                }
                $relative = ltrim(str_replace($workerPath, '', $file->getPathname()), '/\\');
                $modules[$relative] = (string) file_get_contents($file->getPathname());
            }
            if (! isset($modules[$configuredEntry])) {
                throw new RuntimeException(sprintf(
                    '%s build wrote %s/ but %s is missing — check the build log for adapter errors.',
                    $frameworkLabel,
                    $workerPath,
                    $configuredEntry,
                ));
            }
            $totalBytes = array_sum(array_map('strlen', $modules));
            if ($totalBytes > self::SSR_SCRIPT_MAX_BYTES) {
                throw new RuntimeException(sprintf(
                    'SSR worker bundle (%s, %d modules) exceeds the per-script size limit (%s bytes).',
                    $frameworkLabel,
                    count($modules),
                    number_format(self::SSR_SCRIPT_MAX_BYTES),
                ));
            }

            return [$configuredEntry, $modules];
        }

        if (! is_file($workerPath)) {
            throw new RuntimeException(sprintf(
                '%s build did not produce %s — check the build log for adapter errors.',
                $frameworkLabel,
                $workerPath,
            ));
        }
        $bytes = filesize($workerPath);
        if ($bytes === false || $bytes > self::SSR_SCRIPT_MAX_BYTES) {
            throw new RuntimeException(sprintf(
                'SSR worker script (%s) exceeds the per-script size limit (%s bytes).',
                $frameworkLabel,
                number_format(self::SSR_SCRIPT_MAX_BYTES),
            ));
        }
        $singleName = basename($workerPath);

        return [$singleName, [$singleName => (string) file_get_contents($workerPath)]];
    }

    /**
     * Build a useful failure message even when docker run exited non-zero
     * with empty stderr (common when the container's failing process wrote
     * everything to stdout — pnpm, npm, vite, etc.). Falls back through:
     * stderr → stdout tail → tail of build.log → bare exit code so the
     * "Build failed:" callout in the UI is never empty.
     */
    private function summarizeBuildFailure(ProcessResult $build, string $buildLogPath): string
    {
        $exit = $build->exitCode();

        $stderr = trim($build->errorOutput());
        if ($stderr !== '') {
            return 'Build failed (exit '.$exit.'): '.$this->tailLines($stderr, 40);
        }

        $stdout = trim($build->output());
        if ($stdout !== '') {
            return 'Build failed (exit '.$exit.'): '.$this->tailLines($stdout, 40);
        }

        // Last resort — read the persisted build log file so we don't ship
        // a literal "Build failed:" with no detail.
        if (is_file($buildLogPath) && is_readable($buildLogPath)) {
            $tail = @file_get_contents($buildLogPath);
            if (is_string($tail) && trim($tail) !== '') {
                return 'Build failed (exit '.$exit.'): '.$this->tailLines($tail, 40);
            }
        }

        return 'Build failed with exit code '.$exit.' and no captured output. The build process likely crashed before printing anything — check the container image and the script.';
    }

    private function tailLines(string $text, int $maxLines): string
    {
        $lines = preg_split('/\r?\n/', trim($text)) ?: [];
        if (count($lines) <= $maxLines) {
            return implode("\n", $lines);
        }

        return '… ('.(count($lines) - $maxLines)." earlier lines)\n".implode("\n", array_slice($lines, -$maxLines));
    }

    /**
     * Compose the bash script that runs inside the build container.
     *
     * Strategy:
     *   1. Always use the install step we detected from the repo's lockfile
     *      (pnpm install on pnpm-lock.yaml, yarn install on yarn.lock, etc.).
     *      The user's `build_command` often starts with a default `npm ci`
     *      they never explicitly chose — running that on a pnpm repo
     *      either fails (no package-lock.json) or installs into the wrong
     *      layout (npm ignores pnpm's symlinks → `sh: astro: not found`).
     *   2. Strip any leading `<pm> install/ci && ` from the build command
     *      so we don't install twice.
     *   3. Translate `npm run X` / `npm exec X` to the active PM
     *      (`pnpm run X` / `yarn run X`) so the build script finds the
     *      binaries that pnpm/yarn put in their own bin paths.
     */
    private function composeBuildScript(
        string $checkout,
        string $buildCommand,
        string $checkoutRoot = '',
        string $repoRoot = '',
    ): string {
        $install = $this->detectInstallCommand($checkout);
        $pmName = $this->detectPackageManagerName(
            ($checkoutRoot !== '' && $this->isWorkspaceRoot($checkoutRoot))
                ? $checkoutRoot
                : $checkout,
        );

        if (
            $install !== null
            && $checkoutRoot !== ''
            && $repoRoot !== ''
            && (bool) config('edge.build.monorepo_filter_install', true)
            && $this->isWorkspaceRoot($checkoutRoot)
        ) {
            $filtered = $this->detectFilteredInstallCommand($checkoutRoot, $checkout, $repoRoot, $pmName);
            if ($filtered !== null) {
                $install = $filtered;
            }
        }

        // Prefer workspace-root lockfile install when the package dir has
        // package.json but no lockfile (typical monorepo leaf).
        if ($install === null && $checkoutRoot !== '' && $this->isWorkspaceRoot($checkoutRoot)) {
            $install = $this->detectInstallCommand($checkoutRoot);
            if ($install !== null && $repoRoot !== '' && (bool) config('edge.build.monorepo_filter_install', true)) {
                $filtered = $this->detectFilteredInstallCommand($checkoutRoot, $checkout, $repoRoot, $pmName);
                if ($filtered !== null) {
                    $install = $filtered;
                }
            }
        }

        if ($install === null) {
            return $buildCommand;
        }

        // Strip a leading install prefix added by the create-form default
        // (`npm ci && X`, `pnpm install && X`, etc.) so we don't run install
        // twice with possibly-conflicting commands.
        $stripped = preg_replace(
            '#^\s*(?:npm\s+(?:ci|install)|pnpm\s+install|yarn\s+install|bun\s+install)\s*&&\s*#i',
            '',
            $buildCommand,
            1,
        );
        $rest = is_string($stripped) ? trim($stripped) : trim($buildCommand);

        // Re-target `npm run`/`npm exec` to the actual package manager so
        // bin lookups (astro, vite, next, etc.) work on the right tree.
        if ($pmName !== 'npm') {
            $rewritten = preg_replace(
                '/\bnpm\s+(run|exec)\b/i',
                $pmName.' $1',
                $rest,
            );
            if (is_string($rewritten)) {
                $rest = $rewritten;
            }
        }

        // Add --if-present to any `<pm> run <script>` invocation so a repo
        // without a "build" script (or whichever named script) doesn't
        // hard-fail with ERR_PNPM_NO_SCRIPT / npm ERR! missing-script.
        // It's still surfaced in the log ("[script not present]" or
        // similar) so the operator can see what was skipped.
        $rest = preg_replace_callback(
            '/\b(npm|pnpm|yarn|bun)\s+run\s+(\S+)/i',
            function (array $m): string {
                // Skip if --if-present already specified.
                if (str_contains($m[0], '--if-present')) {
                    return $m[0];
                }

                return $m[1].' run --if-present '.$m[2];
            },
            $rest,
        ) ?? $rest;

        // Install may `cd /src` for monorepo filtered installs. Docker sets
        // `-w` to the package dir, but an explicit cd in the install script
        // leaves the shell at the workspace root — so without this, `pnpm run
        // build` executes the monorepo root turbo script instead of the
        // selected package (e.g. examples/blog).
        if ($repoRoot !== '') {
            $packageWorkdir = '/src/'.trim(str_replace('\\', '/', $repoRoot), '/');
            $rest = 'cd '.escapeshellarg($packageWorkdir).' && '.$rest;
        }

        // Echo phase markers so the journey UI isn't silent during long
        // installs; unbuffer node tooling when possible for live chatter.
        return 'export FORCE_COLOR=1 NPM_CONFIG_PROGRESS=true NPM_CONFIG_LOGLEVEL=info PNPM_LOG_LEVEL=info'
            .' && echo "[dply] Installing dependencies…"'
            .' && '.$install
            .' && echo "[dply] Running build…"'
            .' && '.$rest;
    }

    private function detectPackageManagerName(string $checkout): string
    {
        if (is_file($checkout.'/pnpm-lock.yaml')) {
            return 'pnpm';
        }
        if (is_file($checkout.'/yarn.lock')) {
            return 'yarn';
        }
        if (is_file($checkout.'/bun.lockb') || is_file($checkout.'/bun.lock')) {
            return 'bun';
        }

        return 'npm';
    }

    private function detectInstallCommand(string $checkout): ?string
    {
        if (is_file($checkout.'/pnpm-lock.yaml')) {
            return $this->corepackPnpmInstall($checkout);
        }
        if (is_file($checkout.'/yarn.lock')) {
            return $this->corepackYarnInstall($checkout);
        }
        if (is_file($checkout.'/bun.lockb') || is_file($checkout.'/bun.lock')) {
            return 'npm install -g bun && bun install --frozen-lockfile';
        }
        if (is_file($checkout.'/package-lock.json')) {
            return 'npm ci';
        }
        if (is_file($checkout.'/package.json')) {
            return 'npm install';
        }

        return null;
    }

    /**
     * Build a pnpm install command that handles two compatibility traps
     * gracefully:
     *
     *   1. pnpm 11+ requires Node ≥ 22.13. Running pnpm 11 on Node 20
     *      crashes on `require('node:sqlite')`.
     *   2. pnpm 10 can't read a pnpm-11-generated lockfile
     *      (`ERR_PNPM_LOCKFILE_BREAKING_CHANGE`). So pinning pnpm@10
     *      unconditionally breaks modern repos.
     *
     * The fix: detect Node major IN the container and match a pnpm
     * version that (a) runs on that Node and (b) understands the
     * lockfile format that Node line typically produces:
     *
     *   Node 22+ → pnpm@latest  (handles lockfile v10)
     *   Node 20  → pnpm@10      (handles lockfile v9)
     *   Node 18  → pnpm@9
     *
     * When the repo pins `packageManager`, honor it — that's the most
     * explicit signal we have and a repo author knows their lockfile.
     */
    /**
     * Install a monorepo leaf from the registry, ignoring workspace linking.
     *
     * Used when pnpm-workspace.yaml sets preferWorkspacePackages /
     * linkWorkspacePackages (library monorepos whose examples declare
     * published semvers but would otherwise symlink to unbuilt source).
     * Neutralizes root workspace + root tsconfig so Vite/TS don't walk into
     * missing sibling project references under a sparse checkout.
     */
    private function corepackPnpmLeafIsolationInstall(string $repoRoot): string
    {
        $packageWorkdir = '/src/'.trim(str_replace('\\', '/', $repoRoot), '/');
        $env = 'export COREPACK_ENABLE_DOWNLOAD_PROMPT=0 COREPACK_DEFAULT_TO_LATEST=0 ASTRO_TELEMETRY_DISABLED=1';
        $neutralize = 'cd /src && for f in pnpm-workspace.yaml pnpm-workspace.yml tsconfig.json tsconfig.base.json jsconfig.json; do'
            .' [ -f "$f" ] && mv "$f" "$f.dply-bak" || true; done';
        $allowlistScript = 'echo "[pnpm] leaf isolation (ignore-workspace) — registry deps for '.escapeshellarg($repoRoot).'"';
        $pnpmFlags = '--ignore-workspace --config.dangerouslyAllowAllBuilds=true';
        $install = 'cd '.escapeshellarg($packageWorkdir)
            .' && { set -o pipefail; '.$allowlistScript.'; PNPM_LOG=$(mktemp);'
            .' if pnpm install --frozen-lockfile '.$pnpmFlags.' 2>&1 | tee "$PNPM_LOG"; then :;'
            .' else echo "[pnpm] frozen-lockfile unavailable in leaf — retrying without";'
            .' pnpm install --no-frozen-lockfile '.$pnpmFlags.'; fi; }';

        return $env.' && corepack enable && corepack prepare --activate && '.$neutralize.' && '.$install;
    }

    private function prefersWorkspacePackages(string $workspaceRoot): bool
    {
        foreach (['pnpm-workspace.yaml', 'pnpm-workspace.yml'] as $file) {
            $path = $workspaceRoot.'/'.$file;
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
     * @param  ?string  $filterSpec  pnpm filter (e.g. "./apps/web...") run from /src
     */
    private function corepackPnpmInstall(string $checkout, ?string $filterSpec = null): string
    {
        $env = 'export COREPACK_ENABLE_DOWNLOAD_PROMPT=0 COREPACK_DEFAULT_TO_LATEST=0';
        $filterFlag = $filterSpec !== null && $filterSpec !== ''
            ? ' --filter '.escapeshellarg($filterSpec)
            : '';
        $cdPrefix = $filterFlag !== '' ? 'cd /src && ' : '';

        // Run install with two pnpm-11-specific safety nets:
        //
        //  1. Pre-approve build scripts. pnpm 11 errors with
        //     ERR_PNPM_IGNORED_BUILDS when ANY package wants to run an
        //     install script (sharp, esbuild, etc.) and isn't on the
        //     allowlist. pnpm 11.3 *removed* the `pnpm.onlyBuiltDependencies`
        //     field from package.json (the install warns "The 'pnpm' field
        //     in package.json is no longer read"). The new home is `.npmrc`
        //     via `only-built-dependencies[]=<name>` entries. We append
        //     the usual native-binary suspects + every direct dep so the
        //     CI build downloads + compiles binaries cleanly.
        //
        //  2. --frozen-lockfile first; fall back to --no-frozen-lockfile
        //     only on ERR_PNPM_LOCKFILE_BREAKING_CHANGE (lockfile newer
        //     than the running pnpm). Other failures still exit loudly.
        //
        // Wrapped in `{ ...; }` so the whole block is a single statement
        // the upstream `&&` chain can short-circuit through.
        // `set -o pipefail` makes `pnpm … | tee` reflect pnpm's exit.
        // Build-script approval is now passed via pnpm's CLI config flag
        // rather than written into pnpm-workspace.yaml. The file
        // approach broke on non-workspace repos because the file's
        // mere presence flipped pnpm into workspace-detection mode
        // (`Scope: all 6 workspace projects`), which then conflicted
        // with transitive deps' `neverBuiltDependencies` and triggered
        // ERR_PNPM_CONFIG_CONFLICT_BUILT_DEPENDENCIES.
        //
        // `--config.dangerouslyAllowAllBuilds=true` is the same setting,
        // applied at command time only, no on-disk state. The previous
        // ERR_PNPM_IGNORED_BUILDS case still gets suppressed.
        $allowlistScript = 'echo "[pnpm] dangerouslyAllowAllBuilds enabled via CLI flag"';
        $pnpmFlags = '--config.dangerouslyAllowAllBuilds=true'.$filterFlag;

        $install = $cdPrefix.'{ set -o pipefail; '.$allowlistScript.'; PNPM_LOG=$(mktemp);'
            .' if pnpm install --frozen-lockfile '.$pnpmFlags.' 2>&1 | tee "$PNPM_LOG"; then :;'
            .' elif grep -q ERR_PNPM_LOCKFILE_BREAKING_CHANGE "$PNPM_LOG"; then'
            .' echo "[pnpm] lockfile is newer than the installed pnpm — falling back to --no-frozen-lockfile";'
            .' pnpm install --no-frozen-lockfile '.$pnpmFlags.';'
            .' else exit 1; fi; }';

        if ($this->packageJsonHasPackageManager($checkout)) {
            return $env." && corepack enable && corepack prepare --activate && {$install}";
        }

        // pnpm version resolution at container runtime:
        //   - Ask npm what the actual latest pnpm version is (corepack
        //     ships with a stale bundled `latest` map that resolves to
        //     buggy 11.3.0, and corepack rejects partial semver pins
        //     like `pnpm@11.4`, so we need a real semver). `npm view`
        //     hits the registry and returns whatever the current
        //     dist-tag points to — same source corepack would use if
        //     its map weren't stale.
        //   - Older Node majors get a fixed semver compatible with
        //     their runtime and lockfile expectations.
        //   - If npm view fails (offline, registry down) fall back to a
        //     known-good 11.x semver.
        $pickPnpm = 'NODE_MAJOR=$(node -e "console.log(process.versions.node.split(\".\")[0])")'
            .'; if [ "$NODE_MAJOR" -ge 22 ]; then'
            .' PNPM_PIN=$(npm view pnpm@latest version 2>/dev/null);'
            .' [ -z "$PNPM_PIN" ] && PNPM_PIN=11.5.0;'
            .' elif [ "$NODE_MAJOR" -ge 20 ]; then PNPM_PIN=10.0.0;'
            .' else PNPM_PIN=9.15.0; fi'
            .'; echo "[pnpm] Using pnpm@${PNPM_PIN} on Node ${NODE_MAJOR}"';

        return $env." && {$pickPnpm} && corepack enable && corepack prepare pnpm@\${PNPM_PIN} --activate && {$install}";
    }

    private function corepackYarnInstall(string $checkout): string
    {
        $env = 'export COREPACK_ENABLE_DOWNLOAD_PROMPT=0 COREPACK_DEFAULT_TO_LATEST=0';

        if ($this->packageJsonHasPackageManager($checkout)) {
            return $env.' && corepack enable && corepack prepare --activate && yarn install --frozen-lockfile';
        }

        // Yarn 4 runs on Node ≥ 18 — no version-specific traps as severe
        // as pnpm's. Pin to yarn@4 broadly; modern lockfiles need v4+.
        return $env.' && corepack enable && corepack prepare yarn@4 --activate && yarn install --frozen-lockfile';
    }

    private function packageJsonHasPackageManager(string $checkout): bool
    {
        $path = $checkout.'/package.json';
        if (! is_file($path) || ! is_readable($path)) {
            return false;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return false;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return false;
        }

        return is_string($decoded['packageManager'] ?? null) && $decoded['packageManager'] !== '';
    }

    /**
     * Pull the build image only when it is not already present locally.
     * Warm workers (and repeat deploys) skip a 30–90s no-op pull.
     */
    private function ensureDockerImage(string $dockerImage, string $buildLog): void
    {
        if ((bool) config('edge.build.skip_pull_if_present', true)) {
            $inspect = Process::timeout(30)->run([
                'docker', 'image', 'inspect', $dockerImage,
                '--format', '{{.Id}}',
            ]);
            if ($inspect->successful()) {
                $this->appendBuildLog($buildLog, "Using local image {$dockerImage} (skip pull).\n");

                return;
            }
        }

        $this->appendBuildLog($buildLog, "Pulling image {$dockerImage}…\n");
        $pull = Process::timeout(900)
            ->run(
                ['docker', 'pull', $dockerImage],
                fn (string $type, string $chunk) => $this->appendBuildLog($buildLog, $chunk),
            );
        if (! $pull->successful()) {
            $detail = trim($pull->errorOutput()) !== '' ? trim($pull->errorOutput()) : trim($pull->output());
            $detail = $detail !== '' ? $detail : 'no output from docker pull (exit '.$pull->exitCode().')';
            throw new RuntimeException('Docker pull failed for '.$dockerImage.': '.$detail);
        }
    }

    /**
     * Host-side npm/pnpm/yarn caches bind-mounted into the build container.
     *
     * @return list<string>
     */
    private function packageStoreVolumeFlags(): array
    {
        if (! (bool) config('edge.build.package_store_enabled', true)) {
            return [];
        }

        $root = rtrim((string) config('edge.build.package_store_dir', storage_path('app/edge-pkg-store')), '/');
        File::ensureDirectoryExists($root.'/npm');
        File::ensureDirectoryExists($root.'/pnpm');
        File::ensureDirectoryExists($root.'/yarn');

        return [
            '-v', $root.'/npm:/npm-cache',
            '-v', $root.'/pnpm:/pnpm-store',
            '-v', $root.'/yarn:/yarn-cache',
        ];
    }

    private function isWorkspaceRoot(string $root): bool
    {
        if (is_file($root.'/pnpm-workspace.yaml') || is_file($root.'/pnpm-workspace.yml')) {
            return true;
        }

        $pkgPath = $root.'/package.json';
        if (! is_file($pkgPath) || ! is_readable($pkgPath)) {
            return false;
        }

        $decoded = json_decode((string) file_get_contents($pkgPath), true);
        if (! is_array($decoded)) {
            return false;
        }

        return isset($decoded['workspaces']);
    }

    /**
     * Filtered install so a monorepo package does not install every workspace.
     */
    private function detectFilteredInstallCommand(
        string $workspaceRoot,
        string $packageDir,
        string $repoRoot,
        string $pmName,
    ): ?string {
        $filterPath = './'.trim($repoRoot, '/').'...';

        if ($pmName === 'pnpm' || is_file($workspaceRoot.'/pnpm-lock.yaml') || is_file($workspaceRoot.'/pnpm-workspace.yaml')) {
            // Library monorepos (withastro/astro, etc.) force workspace links
            // to unbuilt source packages. Isolate the leaf so install pulls
            // published registry versions matching package.json semver.
            if ($this->prefersWorkspacePackages($workspaceRoot)) {
                return $this->corepackPnpmLeafIsolationInstall($repoRoot);
            }

            return $this->corepackPnpmInstall($workspaceRoot, $filterPath);
        }

        $packageName = $this->packageJsonName($packageDir);

        if (($pmName === 'npm' || is_file($workspaceRoot.'/package-lock.json')) && $packageName !== null) {
            return 'cd /src && (npm ci -w '.escapeshellarg($packageName)
                .' || npm install -w '.escapeshellarg($packageName).')';
        }

        if (($pmName === 'yarn' || is_file($workspaceRoot.'/yarn.lock')) && $packageName !== null) {
            // yarn workspaces focus needs Yarn Berry; fall back to full install.
            return '('.$this->corepackYarnInstall($workspaceRoot)
                .' && (yarn workspaces focus '.escapeshellarg($packageName)
                .' || true))';
        }

        return null;
    }

    private function packageJsonName(string $dir): ?string
    {
        $path = $dir.'/package.json';
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return null;
        }

        $name = $decoded['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function assertDockerAvailable(string $buildLog): void
    {
        if (EdgeBuildDockerBootstrap::daemonReachable()) {
            return;
        }

        $probe = EdgeBuildDockerBootstrap::probeDetail();
        $this->appendBuildLog($buildLog, "[dply:docker] Daemon unreachable — {$probe}\n");

        // macOS / local: get.docker.com + systemctl cannot install OrbStack /
        // Docker Desktop. The CLI binary often exists while the daemon is
        // asleep — autoinstall used to skip install, no-op systemctl, and
        // mis-report "passwordless sudo".
        if (EdgeBuildDockerBootstrap::isLocalDesktopEnvironment()) {
            throw new RuntimeException(
                'Edge build requires Docker but the daemon is not reachable. '
                .'Start OrbStack or Docker Desktop (confirm `docker version` shows a Server), then re-run the deploy. '
                .'For local UI-only work set DPLY_FAKE_EDGE=true to skip real Docker builds. '
                ."Detail: {$probe}"
            );
        }

        if (! (bool) config('edge.build.docker_autoinstall', true)) {
            throw new RuntimeException(
                'Edge build requires Docker but the daemon is not reachable. '
                .'On the control-plane worker run: '
                .'`sudo php artisan dply:edge:ensure-build-docker` '
                .'(grants '.EdgeBuildDockerBootstrap::queueUser().') then `php artisan horizon:terminate`. '
                ."Detail: {$probe}"
            );
        }

        $this->installDocker($buildLog);

        if (EdgeBuildDockerBootstrap::daemonReachable()) {
            return;
        }

        $after = EdgeBuildDockerBootstrap::probeDetail();
        $user = EdgeBuildDockerBootstrap::queueUser();
        throw new RuntimeException(
            'Edge build could not start Docker on this control-plane worker. '
            ."Horizon runs as {$user} and usually lacks passwordless sudo — fix once as root: "
            .'`sudo php artisan dply:edge:ensure-build-docker` '
            .'then `php artisan horizon:terminate`. '
            .'Verify with `php artisan dply:edge:ensure-build-docker --check`. '
            ."Detail: {$after}"
        );
    }

    /**
     * Best-effort inline Docker install for a Linux build worker that came up
     * without it. Prefer {@see EdgeEnsureBuildDockerCommand} on prod (root).
     */
    private function installDocker(string $buildLog): void
    {
        EdgeBuildDockerBootstrap::ensure(
            EdgeBuildDockerBootstrap::queueUser(),
            fn (string $chunk) => $this->appendBuildLog($buildLog, $chunk),
        );
    }

    private function assertArtifactContents(string $dir, string $outputDir): void
    {
        $hasFile = false;
        $hasIndex = false;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $hasFile = true;
            $relative = ltrim(str_replace($dir, '', $file->getPathname()), '/\\');
            if ($relative === 'index.html') {
                $hasIndex = true;
                break;
            }
        }
        if (! $hasFile) {
            throw new RuntimeException("Build produced no files in output directory: {$outputDir}");
        }
        if (! $hasIndex) {
            throw new RuntimeException("Build output is missing index.html at the root of: {$outputDir}");
        }
    }

    private function assertArtifactSize(string $dir): void
    {
        $max = (int) config('edge.build.artifact_max_bytes', 524_288_000);
        $total = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $total += $file->getSize();
                if ($total > $max) {
                    throw new RuntimeException('Build artifacts exceed maximum allowed size.');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $env
     * @return list<string>
     */
    private function dockerEnvFlags(array $env): array
    {
        // Defaults encourage npm/pnpm/vite to emit progress instead of
        // buffering quietly in a non-TTY docker run. Site env wins on conflict.
        $storeDefaults = [];
        if ((bool) config('edge.build.package_store_enabled', true)) {
            $storeDefaults = [
                'npm_config_cache' => '/npm-cache',
                'PNPM_STORE_DIR' => '/pnpm-store',
                'YARN_CACHE_FOLDER' => '/yarn-cache',
            ];
        }

        $merged = array_merge([
            'FORCE_COLOR' => '1',
            'NPM_CONFIG_PROGRESS' => 'true',
            'NPM_CONFIG_LOGLEVEL' => 'info',
            'PNPM_LOG_LEVEL' => 'info',
        ], $storeDefaults, $env);

        $flags = [];
        foreach ($merged as $key => $value) {
            if ($key === '') {
                continue;
            }
            $flags[] = '-e';
            $flags[] = $key.'='.$value;
        }

        return $flags;
    }
}
