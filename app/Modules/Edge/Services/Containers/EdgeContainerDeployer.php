<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Containers;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Billing\Services\StarterTrafficGate;
use App\Modules\Edge\Services\EdgeBuildRunner;
use App\Modules\Edge\Services\EdgeDeliveryContextResolver;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use App\Modules\Edge\Services\EdgeQueueConsumers;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeCronExpression;
use App\Modules\Edge\Support\EdgeEffectiveBindings;
use App\Modules\Edge\Support\EdgeEffectiveCrons;
use App\Modules\Edge\Support\EdgeLogCopy;
use App\Modules\Edge\Support\EdgeMeter;
use App\Modules\Edge\Support\EdgeQueueWorkers;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Deploys a container site: generates a small Worker project (a
 * `@cloudflare/containers` class fronting the app image, a queue consumer that
 * pushes batches into the app, and a queue producer endpoint the app calls),
 * then runs `wrangler deploy --dispatch-namespace` in the deployer image
 * against the host Docker socket. wrangler builds the image in the isolated
 * BuildKit builder (deployerScript), pushes it and rolls the container out.
 *
 * One script per site (`dply-ctr-<site>`), not per deployment: the container
 * application and its Durable Objects hang off the script, so a per-deploy
 * name would leave an orphaned container app behind every deploy.
 * ponytail: rollback to an older deployment doesn't restore its image — the
 * script always runs the latest build; keep image tags per deploy if needed.
 */
class EdgeContainerDeployer
{
    /** Silence longer than this during a deploy gets a heartbeat line. */
    private const HEARTBEAT_AFTER_SECONDS = 30;

    /**
     * A database round trip above this (ms) means the app landed far from its
     * data (next door is ~13 ms). 50, not 40: Houston or Chicago to nyc3 is
     * ~41 ms and a restart rarely lands closer, so 40 cost minutes a deploy.
     */
    public const FAR_FROM_DATABASE_MS = 50;

    /** Restarts of the web instance to try for a closer placement. */
    public const REPLACE_ATTEMPTS = 2;

    public const QUEUE_PATH = '/_dply/queue';

    public const QUEUE_SEND_PATH = '/_dply/queue/send';

    public const SCHEDULE_PATH = '/_dply/schedule';

    /** Analytics Engine dataset of container reply bytes (countReply in the Worker). */
    public const REPLY_BYTES_DATASET = 'dply_container_bytes';

    public static function scriptName(Site $site): string
    {
        return 'dply-ctr-'.strtolower((string) $site->id);
    }

    /**
     * Connections for the worker. Another-app rows carry the other app's
     * script name and public origin so the worker can call it directly.
     *
     * @return list<array<string, mixed>>
     */
    private function workerConnections(Site $site): array
    {
        $peers = [];
        foreach (EdgeContainerConnections::peerApps($site) as $peer) {
            $peers[$peer['id']] = $peer;
        }

        return array_map(static function (array $connection) use ($peers): array {
            if ($connection['kind'] !== 'service') {
                return $connection;
            }
            $peer = $peers[$connection['target']] ?? null;
            $connection['script'] = $peer['script'] ?? '';
            $connection['origin'] = $peer['origin'] ?? '';

            return $connection;
        }, EdgeContainerConnections::for($site));
    }

    /**
     * Run the deployer and emit a heartbeat while it is silent.
     *
     * After the image layers are pushed, wrangler waits on Cloudflare to
     * ingest the image and roll the container out — minutes with no output.
     * Without a heartbeat there is nothing to distinguish that from a dead
     * worker, so the operator is left guessing.
     *
     * @param  list<string>  $command
     */
    private function runWithHeartbeat(callable $log, PendingProcess $pending, array $command): ProcessResult
    {
        $startedAt = microtime(true);
        $lastOutputAt = $startedAt;

        $process = $pending->start($command, function (string $type, string $output) use ($log, &$lastOutputAt): void {
            $lastOutputAt = microtime(true);
            $log($output);
        });

        while ($process->running()) {
            usleep(500_000);

            if (microtime(true) - $lastOutputAt >= self::HEARTBEAT_AFTER_SECONDS) {
                $log(sprintf(
                    "… still deploying — %s elapsed, waiting on Dply Edge.\n",
                    gmdate('i:s', (int) (microtime(true) - $startedAt)),
                ));
                $lastOutputAt = microtime(true);
            }
        }

        return $process->wait();
    }

    /**
     * The lines a human needs out of a failed deploy.
     *
     * Tailing the last N bytes returns BuildKit progress — layer digests and
     * `… done` — which reads as a nonsense error and hides the real one. Keep
     * the lines wrangler/docker actually mark as errors, and only fall back to
     * the tail when nothing matches.
     */
    public static function failureReason(string $stderr, string $stdout = ''): string
    {
        $combined = trim($stderr."\n".$stdout);
        $matched = array_values(array_filter(
            preg_split('/\R/', $combined) ?: [],
            static fn (string $line): bool => preg_match(
                '/(✘|✗|\berror\b|\bfatal\b|\bdenied\b|unauthorized|not found|exit code|failed to|cannot |ERROR:)/i',
                $line,
            ) === 1 && preg_match('/^#\d+ \d+\.\d+ /', $line) !== 1,
        ));

        $text = $matched !== []
            ? implode("\n", array_slice($matched, -8))
            : trim(substr($combined, -800));

        $text = EdgeLogCopy::forCustomer(trim($text) !== '' ? trim($text) : 'no output captured');

        return trim($text) !== '' ? trim($text) : 'no output captured';
    }

    /**
     * A Laravel app gets dply/laravel on the next image when a key-value
     * store, bucket, queue, or the scheduler is attached and the app does
     * not already require the package. Redis uses Laravel's own client.
     */
    public static function needsLaravelPackage(Site $site, string $checkout): bool
    {
        if (! is_file($checkout.'/artisan') || is_file($checkout.'/Dockerfile') || ! is_file($checkout.'/composer.json')) {
            return false;
        }
        $composer = json_decode((string) file_get_contents($checkout.'/composer.json'), true);
        if (is_array($composer) && isset($composer['require']['dply/laravel'])) {
            return false;
        }
        if (EdgeContainerSettings::for($site)['scheduler']) {
            return true;
        }
        // The workspace's database tools (migrate, status, seed) and queue
        // workers (failed jobs, autoscaling) run through /_dply/command.
        if (($site->edgeMeta()['database']['engine'] ?? '') !== '' || EdgeQueueWorkers::for($site)['enabled']) {
            return true;
        }
        foreach (EdgeContainerConnections::for($site) as $connection) {
            if ($connection['asleep']) {
                continue;
            }
            if (in_array($connection['kind'], ['key_value', 'object_storage', 'queue'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The site that is dply's own control plane (edge.self): by id when
     * DPLY_SELF_SITE_ID is set, else by its repository.
     */
    /**
     * Whether the image can run worker mode: what the Container section reads
     * to offer the switch.
     *
     * @param  array{worker_mode?: bool}  $image
     */
    public static function recordWorkerModeSupport(Site $site, array $image): void
    {
        if (($image['worker_mode'] ?? false) !== ($site->edgeMeta()['worker_mode_supported'] ?? false)) {
            $site->mergeEdgeMeta(['worker_mode_supported' => $image['worker_mode'] ?? false]);
            $site->save();
        }
    }

    public static function isSelfSite(Site $site): bool
    {
        $id = trim((string) config('edge.self.site_id'));
        if ($id !== '') {
            return (string) $site->id === $id;
        }
        $repo = strtolower(trim((string) config('edge.self.repo'), '/'));
        $url = strtolower(preg_replace('/\.git$/', '', rtrim((string) $site->git_repository_url, '/')) ?? '');

        return $repo !== '' && $url !== '' && (str_ends_with($url, '/'.$repo) || str_ends_with($url, ':'.$repo) || $url === $repo);
    }

    /** Deterministic so CancelStuckEdgeDeployment can `docker kill` it. */
    public static function buildContainerName(EdgeDeployment $deployment): string
    {
        return 'dply-edge-build-'.strtolower((string) $deployment->id);
    }

    /**
     * Anything that must be running without traffic: min instances, an
     * always-on jobs instance, or a scaling window that raises the minimum.
     * dply:edge:warm-containers re-sends /_dply/warm for these sites.
     */
    public static function keepsInstancesAwake(array $settings): bool
    {
        return $settings['min_instances'] > 0
            || ($settings['worker_instances'] ?? 0) > 0
            || ($settings['dedicated_jobs'] && $settings['jobs_always_on'])
            || array_filter($settings['schedules'], static fn (array $w): bool => $w['min'] > 0) !== [];
    }

    /**
     * Where the app landed and how far that is from its dply database: the
     * app's own round trip, measured from inside the container (dply/laravel
     * db-probe). Cloudflare places by region, not city, so this is how a
     * far-off placement shows up. Best effort: never fails a deploy.
     *
     * @param  callable(string): void  $log
     */
    public function recordPlacement(Site $site, callable $log): void
    {
        if (! self::canProbePlacement($site)) {
            return;
        }
        $probe = static fn (): ?array => self::probePlacement($site);
        $describe = static fn (array $p): string => sprintf('%s (%s), %s ms to the database', $p['location'] ?: '?', $p['region'] ?: '?', rtrim(rtrim(number_format($p['rtt_ms'], 1), '0'), '.'));

        $best = $probe();
        if ($best === null) {
            return;
        }
        $log('Running in '.$describe($best).".\n");
        // Cloudflare places by region, not city: a far landing is re-rolled.
        for ($try = 1; $try <= self::REPLACE_ATTEMPTS && $best['rtt_ms'] > self::FAR_FROM_DATABASE_MS; $try++) {
            $log("That is far for a database round trip. Starting the app again to be placed closer.\n");
            try {
                Http::timeout(120)->withHeaders(['x-dply-queue-token' => self::queueToken($site)])
                    ->post(rtrim((string) $site->edgeLiveUrl(), '/').'/_dply/replace', ['index' => 0])->throw();
            } catch (Throwable $e) {
                $log('Could not restart it: '.$e->getMessage()."\n");
                break;
            }
            $again = $probe();
            if ($again === null) {
                break;
            }
            $log('Now running in '.$describe($again).".\n");
            $same = $again['location'] === $best['location'];
            $best = $again; // what is running now, even if an earlier landing was closer
            if ($same) {
                // Cloudflare chose the same place again (seen on waypost: atl13
                // three times); another restart only costs time.
                break;
            }
        }
        $site->mergeEdgeMeta(['placement' => $best]);
        $site->save();
    }

    /** A Laravel app on a dply Postgres/MySQL: the only apps db-probe measures. */
    public static function canProbePlacement(Site $site): bool
    {
        $database = $site->edgeMeta()['database'] ?? [];

        return $site->isLaravelFrameworkDetected() && is_array($database) && ($database['provider'] ?? '') === 'dply'
            && in_array($database['engine'] ?? '', ['postgres', 'mysql'], true);
    }

    /**
     * Where the app runs now and its round trip to the database, from inside
     * the container. Wakes a sleeping app. Null when it did not answer.
     *
     * @return array{location: string, region: string, rtt_ms: float, at: int}|null
     */
    public static function probePlacement(Site $site): ?array
    {
        try {
            $body = EdgeQueueWorkers::command($site, 'db-probe');
        } catch (Throwable) {
            return null;
        }

        return ($body['ok'] ?? false) ? [
            'location' => strtolower((string) ($body['location'] ?? '')),
            'region' => (string) ($body['region'] ?? ''),
            'rtt_ms' => (float) ($body['rtt_median_ms'] ?? 0),
            'at' => now()->getTimestamp(),
        ] : null;
    }

    /**
     * The app's recent output, oldest first, each line tagged with where it
     * came from: the site Worker in front (routing, wake, scaling), a queue
     * worker ("[dply-worker NAME] …"), or the app itself.
     *
     * @return list<array{at: ?string, level: string, message: string, service: string, source: string, worker: ?string}>
     */
    public static function appLogLines(Site $site, int $minutes = 15, ?string $contains = null): array
    {
        $client = EdgeCloudflareClient::fromConfig();
        $script = self::scriptName($site);

        return array_map(static function (array $line) use ($script): array {
            $worker = preg_match('/^\[dply-worker ([^\]]+)\]/', $line['message'], $m) === 1 ? $m[1] : null;

            return $line + ['source' => $line['service'] === $script ? 'routing' : ($worker !== null ? 'workers' : 'app'), 'worker' => $worker];
        }, array_reverse($client->workerLogs(self::logServices($site, $client), $minutes, contains: $contains !== null && $contains !== '' ? $contains : null)));
    }

    /**
     * Where a container app's logs are: its Worker script, and the container
     * applications wrangler named after it (their stdout/stderr).
     *
     * @return list<string>
     */
    public static function logServices(Site $site, EdgeCloudflareClient $client): array
    {
        $script = self::scriptName($site);
        $services = [$script];
        try {
            foreach ($client->listContainerApplications() as $application) {
                if ($application['id'] !== '' && str_starts_with($application['name'], $script)) {
                    $services[] = $application['id'];
                }
            }
        } catch (Throwable) {
            // A token without Containers Read still shows the Worker's logs.
        }

        return $services;
    }

    /**
     * The image's asset stage runs `npm run build` with no site env, but Vite
     * bakes VITE_* into the JS. Write them to .env.production.local in the
     * checkout (Vite's highest-priority file for a production build).
     * Returns whether anything was written.
     *
     * ponytail: a repo .dockerignore that excludes .env* drops this file; pass
     * them as build args if that turns up.
     *
     * @param  array<string, string>  $env
     */
    public static function writeViteBuildEnv(string $checkout, array $env): bool
    {
        $lines = [];
        foreach ($env as $key => $value) {
            if (str_starts_with($key, 'VITE_') && preg_match('/^[A-Z0-9_]+$/', $key) === 1) {
                $lines[] = $key.'='.json_encode((string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        if ($lines === []) {
            return false;
        }
        File::put($checkout.'/.env.production.local', implode("\n", $lines)."\n");

        return true;
    }

    /** Shared secret between the site Worker and the app for /_dply/* calls. */
    public static function queueToken(Site $site): string
    {
        return hash_hmac('sha256', 'container-queue:'.$site->id, (string) config('app.key'));
    }

    /**
     * @param  array<string, string>  $env  Production env (EdgeProductionEnv)
     * @param  callable(string): void  $log
     * @return array{script_name: string, stack: string, port: int, queues: list<string>}
     */
    public function deploy(Site $site, EdgeDeployment $deployment, string $checkout, string $workRoot, array $env, callable $log, ?int $timeoutSeconds = null): array
    {
        EdgePhpBaseImage::ensure($checkout, $log);
        $injectLaravel = self::needsLaravelPackage($site, $checkout);
        $image = EdgeContainerDockerfile::prepare($checkout, $injectLaravel);
        File::put($image['path'], self::scopeCacheMounts((string) file_get_contents($image['path']), self::cacheScope($site)));
        self::recordWorkerModeSupport($site, $image);
        if ($injectLaravel) {
            $log("Added dply/laravel so this app can use the attached resources.\n");
        }
        if (EdgeContainerSettings::raiseForMemoryCrash($site, $this->memoryEvidence($site))) {
            $log("Logs show the container ran out of memory. Raised the instance size one step.\n");
            $site->refresh();
        }
        $settings = EdgeContainerSettings::for($site, (string) ($image['server'] ?? 'fpm'));
        $this->persistInstanceFloor($site, $settings['instance_type'], $log);
        $log(sprintf("Container image: %s (%s, port %d)\n", $image['generated'] ? 'generated Dockerfile.dply' : 'repo Dockerfile', $image['stack'], $image['port']));
        $summary = EdgeContainerDockerfile::logSummary((string) file_get_contents($image['path']));
        if ($summary !== '') {
            $log($summary);
        }
        $overlap = EdgeContainerSettings::deployOverlap($site);
        $log(sprintf(
            "Container settings: %s, %d instance(s)%s, sleep %s, rollout %s\n",
            $settings['instance_type'],
            $settings['max_instances'],
            $overlap ? ', one extra during this deploy' : '',
            $settings['sleep_after'],
            $settings['rollout_mode'],
        ));

        $withDefaults = EdgeContainerEnvDefaults::ensure($site, $checkout, $env);
        $log(EdgeContainerEnvDefaults::describe($env, $withDefaults));
        $env = $withDefaults;
        // Platform SQLite lives in /tmp, which is empty after every sleep.
        // Saved DB_* vars must not turn migration off, or the next wake has
        // no file and no tables.
        $platformSqlite = ($env['DB_CONNECTION'] ?? '') === 'sqlite'
            && ($env['DB_DATABASE'] ?? '') === '/tmp/database.sqlite';
        $sqliteSync = $platformSqlite && trim((string) config('edge.r2.bucket')) !== '';
        $migrateOnBoot = $settings['migrate_on_boot'] || $platformSqlite;
        if ($sqliteSync) {
            $log("SQLite is saved while the app runs and restored when it wakes. One instance serves the app.\n");
        }

        $project = $workRoot.'/container-worker';
        ['queues' => $queues, 'assets' => $assets] = $this->writeProject($project, $site, $deployment, $checkout, $image, $sqliteSync);
        if ($assets) {
            $log("CSS, JavaScript, and images from public/ are served automatically.\n");
        }

        $env = EdgeContainerConnections::omitAsleepRedis($site, $env);
        if (self::writeViteBuildEnv($checkout, array_merge(EdgeContainerConnections::realtimeBuildEnv($site), $env))) {
            $log("VITE_* variables are passed to the asset build.\n");
        }
        File::put($project.'/secrets.json', json_encode($this->secrets($site, $env, $queues, $migrateOnBoot && ! $site->isEdgePreview(), $sqliteSync), JSON_THROW_ON_ERROR));

        $gitCommit = $this->checkoutCommit($checkout);
        $fingerprint = self::deployFingerprint(
            $gitCommit,
            (string) file_get_contents($image['path']),
            // index.js carries sleep, instance counts and routing; a
            // settings-only change must not look like the live deploy.
            (string) file_get_contents($project.'/wrangler.jsonc').(string) file_get_contents($project.'/src/index.js'),
            (string) file_get_contents($project.'/secrets.json'),
        );
        $unchanged = $this->unchangedLiveContainer($site, $deployment, $gitCommit, $fingerprint);
        if ($unchanged !== null) {
            File::delete($project.'/secrets.json');
            $log("Repo, resources, and container settings match the live deploy. Skipping the image build.\n");

            return $unchanged;
        }

        $this->ensureDeployerImage($log);
        self::ensureBuilderNetwork();

        $namespace = (string) config('edge.cloudflare.dispatch_namespace_name');
        // Migrations run in a release step (dply's own SQLite migrates on boot).
        $release = ! $platformSqlite && ($site->isLaravelFrameworkDetected() || $site->isRailsFrameworkDetected());
        // Production already serves a version: prove the new one works on
        // its own before it takes that traffic. The real deploy below then
        // reuses the image layers this one pushed.
        $candidate = self::checksCandidate($site);
        if ($candidate) {
            $this->deployCandidate($site, $deployment, $project, $workRoot, $namespace, $log, $timeoutSeconds, $release);
        }
        // wrangler goes quiet after the layer push while Cloudflare ingests the
        // image and rolls out the container — minutes, with no output at all.
        // Say so, or every deploy reads as a hang at exactly this point.
        $log("Building the image (npm, Vite, Composer) and pushing it. Docker output follows.\n");

        $result = $this->runWithHeartbeat($log, Process::timeout($timeoutSeconds ?? 1800), self::deployerCommand(
            self::buildContainerName($deployment), $workRoot, $project, $namespace, $settings['rollout_mode'],
        ));

        File::delete($project.'/secrets.json');

        if (! $result->successful()) {
            throw new RuntimeException('Container deploy failed: '.self::failureReason($result->errorOutput(), $result->output()));
        }

        // wrangler returning only means the script uploaded. Ask Cloudflare
        // whether the container actually came up, so "live" means running.
        $log("[dply:step] publish\nImage pushed. Waiting for Dply Edge to roll the container out.\n");
        $rollout = app(EdgeContainerRollout::class)->await($site, $log);
        if ($rollout['settled'] && ! $rollout['ok']) {
            throw new RuntimeException('Container deploy failed: '.(string) $rollout['reason'].' — '.(string) json_encode($rollout['health']));
        }

        // Cloudflare can report the rollout idle while the public URL never
        // answers, or answers with the worker's own "container not running".
        $url = $site->edgeLiveUrl();
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Container deploy failed: the app has no live URL to check.');
        }
        if ($release && ! $candidate) {
            $this->awaitHost($url, $log);
            $this->runRelease($site, $url, $log);
        }
        $log("Checking {$url} answers.\n");
        $checkedAt = time();
        try {
            $response = Http::timeout(90)->withoutRedirecting()->get($url);
        } catch (Throwable $e) {
            throw new RuntimeException("Container deploy failed: {$url} did not answer: ".$e->getMessage(), previous: $e);
        }
        $log(sprintf("App answered HTTP %d.\n", $response->status()));
        if ($response->serverError()) {
            $this->logAppErrors($site, $checkedAt, $log);
        }
        $unhealthy = self::unhealthyReason($url, $response->status(), $response->body());
        if ($unhealthy !== null) {
            throw new RuntimeException('Container deploy failed: '.$unhealthy);
        }

        if (self::keepsInstancesAwake($settings)) {
            $log("Starting the always-on instances.\n");
            try {
                Http::timeout(90)
                    ->withHeaders(['x-dply-queue-token' => self::queueToken($site)])
                    ->post(rtrim($url, '/').'/_dply/warm')
                    ->throw();
            } catch (Throwable $e) {
                // Not fatal: each starts when traffic first reaches it, then stays up.
                $log('Could not start them now: '.$e->getMessage()."\n");
            }
        }

        $this->recordPlacement($site, $log);

        return [
            'script_name' => self::scriptName($site),
            'stack' => $image['stack'],
            'port' => $image['port'],
            'queues' => array_keys($queues),
            'rollout' => $rollout,
            'fingerprint' => $fingerprint,
        ];
    }

    /** The pre-switch copy's Worker script: its own name, so production is untouched. */
    public static function candidateScript(Site $site): string
    {
        return self::scriptName($site).'-next';
    }

    /** Where the pre-switch copy answers: the app's hostname with "--next" on its first label. */
    public static function candidateHost(Site $site): string
    {
        [$label, $rest] = array_pad(explode('.', (string) $site->edgeHostname(), 2), 2, '');

        return $label.'--next'.($rest !== '' ? '.'.$rest : '');
    }

    /**
     * Check a copy first when production already serves this app: a failed
     * check must not reach visitors. Previews have no production to protect.
     */
    public static function checksCandidate(Site $site): bool
    {
        return ! $site->isEdgePreview() && (string) $site->edgeHostname() !== ''
            && EdgeDeployment::query()->where('site_id', $site->id)->where('status', EdgeDeployment::STATUS_LIVE)->exists();
    }

    /**
     * The same Worker project, as the pre-switch copy: its own script name,
     * one instance, and none of production's queue consumers (a queue has
     * one) or its Cron Trigger (every task would run twice).
     */
    public static function asCandidate(string $dir, string $script): void
    {
        $config = json_decode((string) File::get($dir.'/wrangler.jsonc'), true, flags: JSON_THROW_ON_ERROR);
        $config['name'] = $script;
        unset($config['triggers'], $config['queues']['consumers']);
        if (($config['queues'] ?? null) === []) {
            unset($config['queues']);
        }
        if (isset($config['containers'][0])) {
            $config['containers'][0]['max_instances'] = 1;
        }
        File::put($dir.'/wrangler.jsonc', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $package = json_decode((string) File::get($dir.'/package.json'), true, flags: JSON_THROW_ON_ERROR);
        $package['name'] = $script;
        File::put($dir.'/package.json', json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Deploy the new version beside production on candidateHost(), run its
     * migrations, and check it answers. Any failure throws before production
     * is touched. The copy is removed whatever happens.
     *
     * @param  callable(string): void  $log
     */
    private function deployCandidate(Site $site, EdgeDeployment $deployment, string $project, string $workRoot, string $namespace, callable $log, ?int $timeoutSeconds, bool $release): void
    {
        $script = self::candidateScript($site);
        $host = self::candidateHost($site);
        $url = 'https://'.$host;
        $dir = $workRoot.'/container-next';
        File::deleteDirectory($dir);
        File::copyDirectory($project, $dir);
        self::asCandidate($dir, $script);
        $log("Checking the new version on its own before it takes traffic.\n");
        try {
            // Routed first: KV reaches every location while the image builds.
            app(EdgeHostMapPublisher::class)->publishScript($site, $deployment, $host, $script);
            $result = $this->runWithHeartbeat($log, Process::timeout($timeoutSeconds ?? 1800), self::deployerCommand(
                self::buildContainerName($deployment).'-next', $workRoot, $dir, $namespace, 'immediate',
            ));
            if (! $result->successful()) {
                throw new RuntimeException('Container deploy failed: '.self::failureReason($result->errorOutput(), $result->output()));
            }
            $rollout = app(EdgeContainerRollout::class)->await($site, $log, application: $script.'-app');
            if ($rollout['settled'] && ! $rollout['ok']) {
                throw new RuntimeException('Container deploy failed: '.(string) $rollout['reason'].'. Production still runs the previous version.');
            }
            $this->awaitHost($url, $log);
            if ($release) {
                $this->runRelease($site, $url, $log);
            }
            $log("Checking the new version answers at {$url}.\n");
            $checkedAt = time();
            try {
                $response = Http::timeout(90)->withoutRedirecting()->get($url);
            } catch (Throwable $e) {
                throw new RuntimeException("Container deploy failed: the new version did not answer: {$e->getMessage()}. Production still runs the previous version.", previous: $e);
            }
            $log(sprintf("The new version answered HTTP %d.\n", $response->status()));
            if ($response->serverError()) {
                $this->logAppErrors($site, $checkedAt, $log, url: $url);
            }
            $unhealthy = self::unhealthyReason($url, $response->status(), $response->body());
            if ($unhealthy !== null) {
                throw new RuntimeException('Container deploy failed: '.$unhealthy.' Production still runs the previous version.');
            }
            $log("The new version works. Switching production to it.\n");
        } finally {
            File::deleteDirectory($dir); // holds a copy of secrets.json
            $this->removeCandidate($site, $script, $host, $namespace, $log);
        }
    }

    /** The router may not know a new hostname for up to a minute (KV): wait until it does. */
    private function awaitHost(string $url, callable $log, int $wait = 90): void
    {
        $deadline = time() + $wait;
        do {
            try {
                $response = Http::timeout(60)->withoutRedirecting()->get($url);
                if (! ($response->status() === 404 && str_contains($response->body(), 'Host not configured'))) {
                    return;
                }
            } catch (Throwable) {
                // Not answering yet; the check after this says so if it stays that way.
            }
            $log("Waiting for the new address to reach every location…\n");
            Sleep::for(5)->seconds();
        } while (time() < $deadline);
    }

    /**
     * The release step: run the app's migrations once, in the new version,
     * before it takes traffic (dply/laravel and dply-rails "release"). A
     * failure stops the deploy.
     *
     * @param  callable(string): void  $log
     */
    private function runRelease(Site $site, string $url, callable $log): void
    {
        $log($site->isRailsFrameworkDetected() ? "Running migrations (rails db:migrate)…\n" : "Running migrations (php artisan migrate --force)…\n");
        try {
            $response = Http::timeout(900)->withHeaders(['x-dply-queue-token' => self::queueToken($site)])
                ->post(rtrim($url, '/').'/_dply/command', ['command' => 'release']);
        } catch (Throwable $e) {
            throw new RuntimeException('Container deploy failed: migrations did not finish: '.$e->getMessage(), previous: $e);
        }
        if (in_array($response->status(), [404, 422], true)) {
            $log("This app's dply package can't run migrations from a deploy yet, so they were skipped. Update dply/laravel or dply-rails.\n");

            return;
        }
        $body = $response->json();
        $output = trim((string) (is_array($body) ? ($body['output'] ?? $body['error'] ?? '') : $response->body()));
        foreach (array_slice(preg_split('/\R/', $output) ?: [], -20) as $line) {
            if (trim($line) !== '') {
                $log('  '.mb_substr(rtrim($line), 0, 400)."\n");
            }
        }
        if (! $response->successful()) {
            throw new RuntimeException('Container deploy failed: migrations failed'.($output !== '' ? ': '.mb_substr(strtok($output, "\n") ?: $output, 0, 300) : '.'));
        }
    }

    /** Take the pre-switch copy down: its hostname, Worker script and container. Best effort. */
    private function removeCandidate(Site $site, string $script, string $host, string $namespace, callable $log): void
    {
        try {
            app(EdgeHostMapPublisher::class)->unpublishHostname($site, $host);
            $client = EdgeCloudflareClient::fromConfig();
            $client->deleteDispatchScript($namespace, $script);
            foreach ($client->listContainerApplications() as $application) {
                if ($application['id'] !== '' && str_starts_with($application['name'], $script)) {
                    $client->deleteContainerApplication($application['id']);
                }
            }
        } catch (Throwable $e) {
            // Left over, it only costs while awake and the next deploy replaces it; teardown removes it with the app.
            $log('Could not remove the checked copy: '.$e->getMessage()."\n");
        }
    }

    /** A log line that says what broke (Laravel, PHP, the database, nginx). */
    private const APP_ERROR = '/\b(ERROR|CRITICAL|EMERGENCY|Exception|Fatal error|PHP (Fatal|Parse|Warning)|SQLSTATE|Traceback|panic:|No application encryption key|\[error\])\b/i';

    /**
     * The app answered 5xx: say why. First ask the app itself for the tail of
     * its PHP-FPM and nginx logs (dply/laravel runs a shell command through
     * /_dply/schedule), which is instant. Otherwise wait for Workers Logs,
     * which arrive up to a minute late, saying so as it waits. Never fails
     * the deploy: a 500 from the app's code is the app's answer.
     *
     * @param  callable(string): void  $log
     */
    private function logAppErrors(Site $site, int $since, callable $log, int $wait = 75, ?string $url = null): void
    {
        $log("Reading the app's logs for the error…\n");
        $print = static function (array $messages) use ($log): void {
            $log("The app logged:\n");
            foreach (array_slice($messages, -8) as $message) {
                $log('  '.mb_substr(trim($message), 0, 600)."\n");
            }
        };

        try {
            $url = rtrim($url ?? (string) $site->edgeLiveUrl(), '/');
            $body = Http::timeout(30)->withHeaders(['x-dply-queue-token' => self::queueToken($site)])
                ->post($url.self::SCHEDULE_PATH, ['handler' => 'tail -q -n 300 /tmp/php-fpm.log /tmp/nginx-error.log 2>/dev/null'])->json();
            $direct = array_values(array_filter(
                preg_split('/\R/', (string) ($body['output'] ?? '')) ?: [],
                // Not stack frames (#0 …) or tail's file headers (==> x <==).
                static fn (string $line): bool => preg_match(self::APP_ERROR, $line) === 1 && ! str_starts_with(ltrim($line), '#') && ! str_starts_with(ltrim($line), '==>'),
            ));
            if ($direct !== []) {
                $print(array_values(array_unique($direct)));

                return;
            }
        } catch (Throwable) {
            // An app without the newer dply/laravel: fall back to Workers Logs.
        }

        $deadline = time() + $wait;
        $started = time();
        do {
            try {
                $errors = array_values(array_filter(
                    self::appLogLines($site, 5),
                    static fn (array $line): bool => ($line['source'] ?? '') !== 'routing'
                        && strtotime((string) ($line['at'] ?? '')) >= $since - 5
                        && preg_match(self::APP_ERROR, (string) ($line['message'] ?? '')) === 1,
                ));
            } catch (Throwable) {
                $errors = [];
            }
            if ($errors !== []) {
                $print(array_column($errors, 'message'));

                return;
            }
            if (time() < $deadline) {
                Sleep::for(10)->seconds();
                if ((time() - $started) % 20 < 10) {
                    $log(sprintf("Still waiting for the app's logs (%ds)…\n", time() - $started));
                }
            }
        } while (time() < $deadline);
        $log("No error reached the app's logs in time. Open Build & deploy logs → What your app is printing.\n");
    }

    /**
     * The Worker project deploy() hands wrangler: scaffold plus public/
     * assets. Public so dply:self:deploy writes exactly what a dashboard
     * deploy would.
     *
     * @param  array{path: string, port: int, server?: string}  $image
     * @return array{queues: array<string, string>, assets: bool}
     */
    public function writeProject(string $project, Site $site, ?EdgeDeployment $deployment, string $checkout, array $image, bool $sqliteSync = false): array
    {
        $queues = $this->queueBindings($site, $deployment);
        $this->scaffold($project, $site, $image['path'], $image['port'], $queues, self::cronHandlers($site, $deployment), $this->billingKvNamespaceId($site), $sqliteSync, (string) ($image['server'] ?? '') ?: 'fpm');

        return ['queues' => $queues, 'assets' => $this->attachStaticAssets($project, $checkout, $site)];
    }

    /**
     * secrets.json for wrangler: resource driver env, then the app's own env
     * (which wins), then dply's control keys.
     *
     * @param  array<string, string>  $env
     * @param  array<string, string>  $queues
     * @return array<string, string>
     */
    public function secrets(Site $site, array $env, array $queues, bool $migrateOnBoot, bool $sqliteSync = false): array
    {
        $queueEnv = EdgeContainerConnections::queueDriverEnv($site);
        if (! isset($queueEnv['DPLY_QUEUE']) && $queues !== []) {
            $queueEnv['DPLY_QUEUE'] = (string) array_key_first($queues);
            if ($site->isLaravelFrameworkDetected()) {
                $queueEnv['QUEUE_CONNECTION'] = 'dply';
            }
        }

        // A push queue's QUEUE_CONNECTION stays; the app's own env (below) wins over both.
        $queueEnv += EdgeQueueWorkers::dispatchEnv($site);

        return array_merge(EdgeContainerConnections::redisDriverEnv($site), EdgeContainerConnections::storageDriverEnv($site), EdgeContainerConnections::kvDriverEnv($site), EdgeContainerConnections::realtimeDriverEnv($site), $queueEnv, $env, [
            'DPLY_QUEUE_TOKEN' => self::queueToken($site),
            'DPLY_APP_URL' => (string) ($site->edgeLiveUrl() ?? ''),
            // Never on a preview: its migrations would run against whatever database it reaches.
            // Never on dply itself: N instances booting a release would race the
            // migration. Its deploy runs `php artisan migrate --force` once instead.
            'DPLY_MIGRATE_ON_BOOT' => $migrateOnBoot && ! self::isSelfSite($site) ? '1' : '0',
            'DPLY_SQLITE_SYNC' => $sqliteSync ? '1' : '0',
            // Inert unless the image has the Octane branch (EdgeContainerDockerfile::supportsWorkerMode).
            'DPLY_WORKER_MODE' => EdgeContainerSettings::for($site)['worker_mode'] ? '1' : '0',
        ], EdgeMeter::workerNames($site) !== [] ? EdgeMeter::env($site) : []);
    }

    /**
     * Why a live-URL check means the app is not up, or null when it is. A 4xx
     * is the app answering (a 404 at / is fine); a 5xx is not — including the
     * worker's own 500 when the container would not start.
     */
    public static function unhealthyReason(string $url, ?int $status, string $body, ?string $error = null): ?string
    {
        if ($status === null) {
            return "{$url} did not answer: ".($error ?? 'no response');
        }
        if ($status < 500) {
            return null;
        }
        // A page's title, else its text: never the CSS or script inside it.
        $title = preg_match('#<title[^>]*>(.*?)</title>#is', $body, $t) === 1 ? trim(html_entity_decode(strip_tags($t[1]))) : '';
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('#<(style|script)\b[^>]*>.*?</\1>#is', ' ', $body))));
        $detail = mb_substr($title !== '' ? $title : $text, 0, 200);

        return preg_match('/not running|Failed to start container|Container crashed|suddenly disconnected|port \d+ is available/i', $body) === 1
            ? "the container did not start ({$url} answered HTTP {$status}: {$detail}). Check the container logs for why it exited."
            : "{$url} answered HTTP {$status}".($detail !== '' ? ": {$detail}" : '.');
    }

    /**
     * Commit, image, worker config, and resource env. A resource or setting
     * change alters wrangler.jsonc or secrets.json, so that deploy still builds.
     */
    public static function deployFingerprint(string $gitCommit, string $dockerfile, string $wrangler, string $secrets): string
    {
        return hash('sha256', $gitCommit."\n".$dockerfile."\n".$wrangler."\n".$secrets);
    }

    private function checkoutCommit(string $checkout): string
    {
        $result = Process::path($checkout)->run(['git', 'rev-parse', 'HEAD']);
        $commit = strtolower(trim($result->output()));

        return $result->successful() && preg_match('/^[0-9a-f]{40}$/', $commit) === 1 ? $commit : '';
    }

    /**
     * @return array{script_name: string, stack: string, port: int, queues: list<string>, rollout: array<string, mixed>, fingerprint: string}|null
     */
    private function unchangedLiveContainer(Site $site, EdgeDeployment $deployment, string $gitCommit, string $fingerprint): ?array
    {
        if ($gitCommit === '') {
            return null;
        }

        $previous = EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->whereKeyNot($deployment->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('created_at')
            ->first();

        $meta = is_array($previous?->meta) ? $previous->meta : [];
        $container = is_array($meta['container'] ?? null) ? $meta['container'] : null;
        if ($container === null || ($container['fingerprint'] ?? '') !== $fingerprint) {
            return null;
        }
        if (! isset($container['script_name'], $container['stack'], $container['port'])) {
            return null;
        }

        $container['fingerprint'] = $fingerprint;

        return $container;
    }

    /**
     * Copy committed files from the app's public directory into the worker
     * so CSS, JavaScript, and images are served without waking the app.
     */
    public function attachStaticAssets(string $project, string $checkout, Site $site): bool
    {
        $root = trim((string) ($site->edgeMeta()['build']['repo_root'] ?? ''), '/');
        $public = $checkout.($root !== '' ? '/'.$root : '').'/public';
        if (! is_dir($public)) {
            return false;
        }

        $dest = $project.'/public';
        File::ensureDirectoryExists($dest);
        $copied = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($public, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($public) + 1);
            if ($relative === false || str_starts_with($relative, 'storage/') || str_starts_with($relative, 'hot') || str_ends_with($relative, '.php')) {
                continue;
            }
            if (! preg_match('/\.(css|js|mjs|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot|txt|xml|webmanifest)$/i', $relative)) {
                continue;
            }
            $target = $dest.'/'.$relative;
            File::ensureDirectoryExists(dirname($target));
            File::copy($file->getPathname(), $target);
            $copied++;
        }
        if ($copied === 0) {
            return false;
        }

        $config = json_decode(File::get($project.'/wrangler.jsonc'), true);
        $config['assets'] = ['directory' => './public', 'binding' => 'ASSETS', 'run_worker_first' => true];
        File::put($project.'/wrangler.jsonc', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return true;
    }

    /**
     * Write the Worker project wrangler deploys.
     *
     * @param  array<string, string>  $queues  binding name => queue name
     * @param  array<string, list<?string>>  $crons  schedule => handlers (artisan command / rake task)
     */
    public function scaffold(string $dir, Site $site, string $dockerfile, int $port, array $queues, array $crons = [], string $kvNamespaceId = '', bool $sqliteSync = false, string $phpServer = 'fpm'): void
    {
        File::ensureDirectoryExists($dir.'/src');
        $settings = EdgeContainerSettings::for($site);

        $config = [
            'name' => self::scriptName($site),
            'main' => 'src/index.js',
            // Containers need a recent runtime; not the SSR scripts' pinned date.
            'compatibility_date' => '2026-06-01',
            'compatibility_flags' => ['nodejs_compat'],
            'containers' => [array_filter([
                'class_name' => 'App',
                'image' => $dockerfile,
                'instance_type' => EdgeContainerSettings::wranglerInstanceType($site),
                'max_instances' => EdgeContainerSettings::wranglerMaxInstances(EdgeContainerSettings::peakInstances($settings), $settings['dedicated_jobs'], EdgeContainerSettings::deployOverlap($site), $settings['worker_instances']),
                'constraints' => EdgeContainerSettings::constraints($site),
                'rollout_step_percentage' => $settings['rollout_step_percentage'] !== [] ? $settings['rollout_step_percentage'] : null,
                'rollout_active_grace_period' => $settings['rollout_active_grace_period'] > 0 ? $settings['rollout_active_grace_period'] : null,
            ])],
            'durable_objects' => ['bindings' => [['name' => 'APP', 'class_name' => 'App']]],
            'migrations' => [
                ['tag' => 'v1', 'new_sqlite_classes' => ['App']],
                ['tag' => 'v2', 'new_sqlite_classes' => ['EdgeState']],
            ],
            // Workers Logs: Worker + container stdout/stderr, read back by the
            // Build & deploy logs through the telemetry query API.
            'observability' => ['enabled' => true],
            // Reply bytes per site (countReply): outbound billing is container
            // tx_bytes minus these (EdgeContainerComputeCost).
            'analytics_engine_datasets' => [['binding' => 'DPLY_BYTES', 'dataset' => self::REPLY_BYTES_DATASET]],
        ];
        if ($queues !== []) {
            $config['queues'] = [
                'producers' => array_map(static fn (string $name, string $queue): array => ['binding' => $name, 'queue' => $queue], array_keys($queues), $queues),
            ];
            // A queue takes one consumer: only the production site processes
            // jobs; previews can enqueue but never steal production's messages.
            // Of several apps on one Resources queue, only the owner consumes.
            if (! $site->isEdgePreview()) {
                $speed = $site->organization !== null ? EdgeQueueConsumers::settings($site->organization) : ['max_wait_time_ms' => 5000];
                $consumed = array_values(array_filter($queues, fn (string $queue): bool => $site->organization === null
                    || EdgeQueueConsumers::owner($site->organization, $queue) === null
                    || EdgeQueueConsumers::owns($site, $queue)));
                if ($consumed !== []) {
                    $config['queues']['consumers'] = array_map(static fn (string $queue): array => array_filter([
                        'queue' => $queue,
                        'max_batch_size' => EdgeQueueConsumers::BATCH_SIZE,
                        'max_retries' => EdgeQueueConsumers::MAX_RETRIES,
                        'max_batch_timeout' => intdiv($speed['max_wait_time_ms'], 1000),
                        'max_concurrency' => $speed['max_concurrency'] ?? null,
                    ], static fn ($v): bool => $v !== null), $consumed);
                }
            }
        }

        if ($crons !== []) {
            // One trigger for every task: scheduled() works out which are due.
            $config['triggers'] = ['crons' => ['* * * * *']];
        }

        if ($kvNamespaceId !== '') {
            $config['kv_namespaces'] = [['binding' => 'BILLING', 'id' => $kvNamespaceId]];
        }

        $bucket = trim((string) config('edge.r2.bucket'));
        if ($sqliteSync && $bucket !== '') {
            $config['r2_buckets'] = [['binding' => 'SQLITE', 'bucket_name' => $bucket]];
        }

        $config = EdgeContainerConnections::mergeWrangler($config, $site);

        File::put($dir.'/wrangler.jsonc', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        File::put($dir.'/package.json', json_encode([
            'name' => self::scriptName($site),
            'private' => true,
            'type' => 'module',
            'dependencies' => EdgeContainerConnections::browserEnabled($site)
                ? ['@cloudflare/containers' => '~0.3.7', '@cloudflare/puppeteer' => '^1']
                // Pinned: autoscaling reads the SDK's inflightRequests.
                : ['@cloudflare/containers' => '~0.3.7'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::put($dir.'/src/index.js', $this->workerSource($port, array_flip($queues), $settings, $crons, $site, $sqliteSync && $bucket !== '', $phpServer));
    }

    /**
     * @param  array<string, string>  $queueBindings  queue name => binding name
     * @param  array{instance_type: string, max_instances: int, sleep_after: string, migrate_on_boot: bool, jurisdiction: string, scheduler: bool}  $settings
     * @param  array<string, list<?string>>  $crons
     */
    private function workerSource(int $port, array $queueBindings, array $settings, array $crons, Site $site, bool $sqliteSync = false, string $phpServer = 'fpm'): string
    {
        $replace = [
            '__PORT__' => (string) $port,
            '__LOCATION_HINT__' => json_encode(strtolower((string) EdgeContainerSettings::dataRegion($site))),
            '__SLEEP__' => json_encode($settings['sleep_after']),
            '__INSTANCES__' => $sqliteSync ? '1' : (string) $settings['max_instances'],
            '__MIN_INSTANCES__' => (string) ($sqliteSync ? min(1, $settings['min_instances']) : $settings['min_instances']),
            '__CAPACITY__' => (string) EdgeContainerSettings::requestsPerInstance($site, $phpServer),
            '__SCHEDULES__' => json_encode($sqliteSync ? [] : $settings['schedules'], JSON_UNESCAPED_SLASHES),
            '__JOBS_ALWAYS_ON__' => $settings['dedicated_jobs'] && $settings['jobs_always_on'] ? 'true' : 'false',
            '__STICKY__' => $settings['sticky_sessions'] ? 'true' : 'false',
            '__DEDICATED_JOBS__' => $settings['dedicated_jobs'] ? 'true' : 'false',
            '__SCHEDULER_WORKER__' => json_encode(EdgeQueueWorkers::runsScheduler($site) ? 'worker-0' : ''),
            '__WORKER_GROUPS__' => json_encode($settings['worker_instances'] > 0 ? array_map(static fn (array $g): array => [
                'key' => $g['key'],
                'prefix' => $g['prefix'],
                'max' => $g['capacity'],
                'min' => min($g['capacity'], $g['instances']),
                'autoscale' => $g['autoscale'],
                'env' => (object) EdgeQueueWorkers::env($site, $g['key']),
            ], EdgeQueueWorkers::groups($site)) : [], JSON_UNESCAPED_SLASHES),
            '__FPM_CHILDREN__' => (string) EdgeContainerSettings::phpFpmPool($settings['instance_type'], $site, $phpServer)['max_children'],
            '__FPM_LIMIT__' => json_encode(EdgeContainerSettings::phpFpmPool($settings['instance_type'], $site)['memory_limit']),
            '__QUEUE_PATH__' => json_encode(self::QUEUE_PATH, JSON_UNESCAPED_SLASHES),
            '__QUEUE_SEND_PATH__' => json_encode(self::QUEUE_SEND_PATH, JSON_UNESCAPED_SLASHES),
            '__QUEUE_BINDINGS__' => json_encode((object) $queueBindings, JSON_UNESCAPED_SLASHES),
            '__SCHEDULE_PATH__' => json_encode(self::SCHEDULE_PATH, JSON_UNESCAPED_SLASHES),
            // New per deploy: a schedule plan from older code is not trusted.
            '__BUILD_ID__' => json_encode(bin2hex(random_bytes(6))),
            '__SITE_ID__' => json_encode(strtolower((string) $site->id)),
            '__CRON_HANDLERS__' => json_encode((object) $crons, JSON_UNESCAPED_SLASHES),
            '__PAUSE_KEY__' => json_encode(StarterTrafficGate::KEY_PREFIX.$site->id),
            '__CONNECTIONS__' => json_encode($this->workerConnections($site), JSON_UNESCAPED_SLASHES),
            '__DPLY_METER__' => EdgeMeter::JS,
            '__CLIENT_CERT__' => json_encode(EdgeContainerConnections::clientCertificateId($site) !== '' ? 'CLIENT_CERT' : ''),
            '__BROWSER__' => EdgeContainerConnections::browserEnabled($site) ? 'true' : 'false',
            '__SQLITE_SYNC__' => $sqliteSync ? 'true' : 'false',
            '__SQLITE_KEY__' => json_encode('sites/'.$site->id.'/sqlite/database.sqlite', JSON_UNESCAPED_SLASHES),
            '__BROWSER_HOST__' => json_encode(EdgeContainerConnections::browserHost($site)),
            '__BROWSER_IMPORT__' => EdgeContainerConnections::browserEnabled($site)
                ? "import puppeteer from '@cloudflare/puppeteer';\n"
                : '',
            '__BROWSER_FETCH__' => EdgeContainerConnections::browserEnabled($site)
                ? <<<'JS'
async function browserFetch(request, env, ctx) {
  if (request.method !== 'POST') return new Response('Send {"url"} as JSON.', { status: 405 });
  const denied = await dplyMeterGate(env, 'browser');
  if (denied) return Response.json({ error: denied.message }, { status: denied.status });
  const body = await request.json();
  if (!body.url) return new Response('Missing url.', { status: 400 });
  const browser = await puppeteer.launch(dplyMetered('browser', env.BROWSER, env, ctx, 'BROWSER'));
  const page = await browser.newPage();
  await page.goto(body.url, { waitUntil: 'networkidle0' });
  const path = new URL(request.url).pathname;
  const response = path.endsWith('/pdf')
    ? new Response(await page.pdf(), { headers: { 'content-type': 'application/pdf' } })
    : path.endsWith('/screenshot')
      ? new Response(await page.screenshot(), { headers: { 'content-type': 'image/png' } })
      : new Response(await page.content(), { headers: { 'content-type': 'text/html; charset=utf-8' } });
  await browser.close();
  return response;
}
JS
                : <<<'JS'
async function browserFetch() {
  return new Response('Browser is off.', { status: 404 });
}
JS,
        ];

        return strtr(<<<'JS'
// Generated by dply (EdgeContainerDeployer). Edits are overwritten on deploy.
import { Container } from '@cloudflare/containers';
import { DurableObject } from 'cloudflare:workers';
export { ContainerProxy } from '@cloudflare/containers';

// The Durable Object location hint: the region of the app's dply data, so the
// DO in front of each container sits near it too. @cloudflare/containers 0.3's
// getContainer takes only a name, hence this one. A hint applies only when a
// DO is first created; instances that already exist stay where they are.
const LOCATION_HINT = __LOCATION_HINT__;
function getContainer(binding, name) {
  return binding.get(binding.idFromName(name), LOCATION_HINT ? { locationHint: LOCATION_HINT } : undefined);
}
__BROWSER_IMPORT__

const QUEUE_BINDINGS = __QUEUE_BINDINGS__; // queue name -> binding name
const CRON_HANDLERS = __CRON_HANDLERS__; // schedule -> [artisan command / rake task]

const CONNECTIONS = __CONNECTIONS__;

__DPLY_METER__

const BUILD_ID = __BUILD_ID__;
const SITE_ID = __SITE_ID__;

// Whether a 5-field cron expression is due at `date` in time zone `tz`:
// numbers, *, ranges, lists, steps, and JAN-DEC / SUN-SAT names (the grammar
// EdgeCronExpression checks in PHP). What it cannot read: lenient (the Laravel
// scheduler's wake plan) counts it as due, since waking early is safe; strict
// (scheduled tasks, one each minute) never runs it, and dply marks it Won't run.
const CRON_NAMES = [null, null, null, ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'], ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT']];
function cronDue(expr, tz, date, strict = false) {
  try {
    const f = String(expr).trim().toUpperCase().split(/\s+/);
    if (f.length !== 5) return !strict;
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', { timeZone: tz || 'UTC', hour12: false, minute: 'numeric', hour: 'numeric', day: 'numeric', month: 'numeric', weekday: 'short' })
      .formatToParts(date).map((p) => [p.type, p.value]));
    const dow = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(parts.weekday);
    const now = [Number(parts.minute), Number(parts.hour) % 24, Number(parts.day), Number(parts.month), dow];
    const ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
    const named = (field, i) => field.replace(/[A-Z]+/g, (name) => {
      const at = CRON_NAMES[i]?.indexOf(name) ?? -1;
      if (at < 0) throw new Error('unsupported');
      return String(i === 3 ? at + 1 : at);
    });
    const hit = (i) => named(f[i], i).split(',').some((part) => {
      const [lo, hi] = ranges[i];
      const [range, stepText] = part.split('/');
      const step = stepText === undefined ? 1 : Number(stepText);
      let [a, b] = range === '*' ? [lo, hi] : range.split('-').map(Number);
      if (b === undefined) b = stepText === undefined ? a : hi;
      if (![a, b, step].every(Number.isInteger) || step < 1) throw new Error('unsupported');
      for (let v = a; v <= b; v += step) if (v === now[i] || (i === 4 && now[i] === 0 && v === 7)) return true;
      return false;
    });
    if (!hit(0) || !hit(1) || !hit(3)) return false;
    // Day of month and day of week: either matches when both are restricted.
    const domAny = f[2] === '*', dowAny = f[4] === '*';
    const domHit = hit(2), dowHit = hit(4);
    return domAny || dowAny ? domHit && dowHit : domHit || dowHit;
  } catch {
    return !strict;
  }
}

// Queue worker groups: instances named {prefix}N (worker-0, worker-high-0, …).
// max can run; the first min are always on, the rest start while dply's
// autoscaler wants them.
const WORKER_GROUPS = __WORKER_GROUPS__;
// The worker that also runs the Laravel scheduler (schedule:work), or ''.
const SCHEDULER_WORKER = __SCHEDULER_WORKER__;
function isWorker(name) { return typeof name === 'string' && name.startsWith('worker-'); }
// The group a worker name belongs to (longest prefix wins: worker-high-0 is
// not the main group's), and its index in it.
function workerGroup(name) {
  let found = null;
  for (const g of WORKER_GROUPS) {
    const rest = String(name).slice(g.prefix.length);
    if (String(name).startsWith(g.prefix) && /^\d+$/.test(rest) && (!found || g.prefix.length > found.group.prefix.length)) {
      found = { group: g, index: Number(rest) };
    }
  }
  return found;
}
function workerNames(group) { return Array.from({ length: group.max }, (_, i) => group.prefix + i); }
function allWorkerNames() { return WORKER_GROUPS.flatMap(workerNames); }

export class App extends Container {
  defaultPort = __PORT__;
  sleepAfter = __SLEEP__;
  interceptHttps = __CLIENT_CERT__ !== '';

  // The SDK probes http://ping and follows redirects. An app that answers
  // with a Location: https://… makes the runtime reject the probe
  // ("Connecting to a container using HTTPS is not currently supported").
  // A redirect means the port is open; the browser follows it, not this hop.
  async waitForPort(waitOptions) {
    const port = waitOptions.portToCheck;
    const tcpPort = this.container.getTcpPort(port);
    const pollInterval = waitOptions.waitInterval ?? 300;
    const tries = waitOptions.retries ?? Math.ceil(20000 / pollInterval);
    for (let i = 0; i < tries; i++) {
      try {
        await tcpPort.fetch('http://' + this.pingEndpoint, { redirect: 'manual' });
        return tries;
      } catch (e) {
        if (!this.container.running || i === tries - 1) {
          const message = e instanceof Error ? e.message : String(e);
          throw new Error('Failed to verify port ' + port + ' is available after ' + ((i + 1) * pollInterval) + 'ms, last error: ' + message);
        }
        await new Promise((resolve) => setTimeout(resolve, pollInterval));
        if (waitOptions.signal?.aborted) throw new Error('Container request aborted.', { cause: e });
      }
    }
    return tries;
  }

  constructor(ctx, env) {
    super(ctx, env);
    // Secrets (site env, DPLY_QUEUE_TOKEN, DPLY_APP_URL) become the app's env.
    const fromWorker = Object.fromEntries(Object.entries(env).filter(([, v]) => typeof v === 'string'));
    // Pool size is computed from the instance memory. It wins over a stale
    // site env var so a crash cannot leave the old child count in place.
    this.envVars = Object.assign(fromWorker, {
      DPLY_PHP_FPM_MAX_CHILDREN: '__FPM_CHILDREN__',
      DPLY_PHP_MEMORY_LIMIT: __FPM_LIMIT__,
    });
    // Queue workers are App instances named worker-N. Whoever starts one
    // (warm, or the platform after a restart), it boots in worker mode.
    const worker = isWorker(ctx.id.name) ? workerGroup(ctx.id.name) : null;
    if (worker) Object.assign(this.envVars, worker.group.env, { DPLY_WORKER_NAME: ctx.id.name }, ctx.id.name === SCHEDULER_WORKER ? { DPLY_WORKER_SCHEDULER: '1' } : {});
  }

  // Queue workers run queue:work, not a web server: start without waiting
  // for a port. Each remembers whether it is wanted: each group's first `min`
  // always are; the autoscaler turns the rest on and off.
  async wanted(index) {
    const flag = await this.ctx.storage.get('dply:wanted');
    const worker = workerGroup(index);
    return flag ?? (worker !== null && worker.index < worker.group.min);
  }

  async startWorker(index) {
    await this.remember(index);
    if (await this.ctx.storage.get('dply:paused')) return;
    await this.ctx.storage.put('dply:wanted', true);
    if (this.container.running) return;
    await this.start({ envVars: this.envVars });
  }

  // SIGTERM: the supervisor lets the running job finish, then exits.
  async stopWorker(index) {
    await this.remember(index);
    await this.ctx.storage.put('dply:wanted', false);
    if (this.container.running) await this.stop('SIGTERM');
  }

  // Bring back a wanted worker Cloudflare restarted.
  async resumeWorker(index) {
    await this.remember(index);
    if ((await this.ctx.storage.get('dply:paused')) || !(await this.wanted(index)) || this.container.running) return;
    await this.start({ envVars: this.envVars });
  }

  // Stop this instance and start it again: Cloudflare places it afresh.
  // dply uses it when a deploy lands far from the app's data.
  async replace(index) {
    await this.remember(index);
    if (this.container.running) {
      await this.stop('SIGTERM');
      for (let i = 0; i < 60 && this.container.running; i++) await new Promise((r) => setTimeout(r, 500));
    }
    await this.startAndWaitForPorts({ ports: [__PORT__], cancellationOptions: { portReadyTimeoutMS: 45000 } });
  }

  // The scheduler's plan lives in the storage of the "dply-schedule"
  // instance, which never starts a container.
  async schedulePlan() {
    return (await this.ctx.storage.get('dply:schedule-plan')) ?? null;
  }

  async saveSchedulePlan(plan) {
    await this.ctx.storage.put('dply:schedule-plan', plan);
  }

  async workerState(index) {
    await this.remember(index);
    return { ...(await this.getState()), wanted: await this.wanted(index), paused: Boolean(await this.ctx.storage.get('dply:paused')) };
  }

  // Paused workers stay stopped through warms, scaling and deploys until
  // resumed. Stopping lets the running job finish.
  async pauseWorker(index, paused) {
    await this.remember(index);
    await this.ctx.storage.put('dply:paused', paused);
    if (paused) {
      if (this.container.running) await this.stop('SIGTERM');
      return;
    }
    await this.ctx.storage.delete('dply:wanted'); // back to the default: the always-on ones run
    await this.resumeWorker(index);
  }

  // Autoscaling. The Worker asks instance-0, instance-1, … in order and
  // sends the request to the first with room, so extra instances only start
  // when the ones before them are full, and go back to sleep when traffic
  // drops. A yes holds a slot until the request arrives (or 30s pass), so a
  // burst at a cold instance does not all pile onto it.
  //
  // WebSockets: the SDK (0.3.7) counts an open socket in inflightRequests
  // until it closes or errors, and its close path shares decrementInflight
  // with plain requests, so sockets cannot be told apart here without a
  // second proxy hop. Each open socket therefore holds one CAPACITY slot for
  // its whole life (new traffic spills to the next instance), and while any
  // socket is open the instance never sleeps: isActivityExpired() is false
  // while inflightRequests > 0, and every message both ways renews the
  // sleepAfter timer. No renewActivityTimeout call is needed. It also means
  // a paused site keeps its open sockets until they close.
  reservations = [];

  async hasRoom(index) {
    await this.remember(index);
    const now = Date.now();
    this.reservations = this.reservations.filter((at) => now - at < 30000);
    if ((this.inflightRequests ?? 0) + this.reservations.length >= CAPACITY) return false;
    this.reservations.push(now);
    return true;
  }

  // The Worker reaches this through the Durable Object fetch handler (not the
  // containerFetch RPC), which is the path the SDK proxies WebSocket
  // upgrades on: it answers with a 101 whose webSocket it pipes both ways.
  async fetch(request) {
    this.reservations.shift();
    this.lastActivityAt = Date.now();
    return super.fetch(request);
  }

  // Last request into this instance, for the dashboard's sleep countdown
  // (/_dply/instances). Memory only: a sleeping instance has no countdown.
  lastActivityAt = null;
  async activity() { return this.lastActivityAt; }

  // The first MIN_INSTANCES instances never sleep (minimum replicas).
  async remember(index) {
    if (this.index === index) return;
    this.index = index;
    await this.ctx.storage.put('dply:index', index);
  }

  async onActivityExpired() {
    const index = this.index ?? (await this.ctx.storage.get('dply:index'));
    const keep = index === 'jobs' ? JOBS_ALWAYS_ON
      : isWorker(index) ? (workerGroup(index)?.index ?? Infinity) < (workerGroup(index)?.group.max ?? 0) && (await this.wanted(index)) && !(await this.ctx.storage.get('dply:paused'))
      : typeof index === 'number' && index < limits().min;
    // A paused site (usage credit used up) lets its always-on instances sleep.
    if (keep && (await trafficOpen(this.env))) return;
    return super.onActivityExpired();
  }
}

const CLIENT_CERT = __CLIENT_CERT__;
const BROWSER = __BROWSER__;
const SQLITE_SYNC = __SQLITE_SYNC__;
const SQLITE_KEY = __SQLITE_KEY__;
App.outboundByHost = Object.fromEntries([
  ...CONNECTIONS.map((c) => [c.host, (request, env, ctx) => connectionFetch(c, request, env, ctx).catch(dplyRefusal)]),
  ...(BROWSER ? [[__BROWSER_HOST__, (request, env, ctx) => browserFetch(request, env, ctx)]] : []),
  ...(SQLITE_SYNC ? [['sqlite.dply', (request, env) => sqliteFetch(request, env)]] : []),
]);

async function sqliteFetch(request, env) {
  if (!env.SQLITE) return new Response('SQLite storage is not configured.', { status: 404 });
  if (request.method === 'GET') {
    const object = await env.SQLITE.get(SQLITE_KEY);
    return object ? new Response(object.body) : new Response('missing', { status: 404 });
  }
  if (request.method === 'PUT') {
    await env.SQLITE.put(SQLITE_KEY, request.body);
    return new Response('ok');
  }
  return new Response('Method not allowed.', { status: 405 });
}
App.outbound = async (request, env) => {
  if (!CLIENT_CERT) return fetch(request);
  const presented = await env[CLIENT_CERT].fetch(request);
  return presented.status === 520 ? fetch(request) : presented;
};

// Header values must be ASCII: escape anything else so the JSON still parses.
const asciiJson = (value) => JSON.stringify(value).replace(/[\u007f-\uffff]/g, (ch) => '\\u' + ch.charCodeAt(0).toString(16).padStart(4, '0'));

async function kvFetch(kv, request, path, url) {
  const refuse = (message) => new Response(message, { status: 400 });
  if (path === '' && request.method === 'GET') {
    const q = url.searchParams;
    const listed = await kv.list({ prefix: q.get('prefix') || undefined, cursor: q.get('cursor') || undefined });
    const detail = q.get('detail') === '1';
    return Response.json({
      keys: (listed.keys || []).map((key) => detail ? { name: key.name, expiration: key.expiration ?? null, metadata: key.metadata ?? null } : key.name),
      cursor: listed.list_complete ? null : listed.cursor,
    });
  }
  if (path === '' && request.method === 'POST') {
    const keys = (await request.json().catch(() => null))?.keys;
    if (!Array.isArray(keys) || keys.length < 1 || keys.length > 100 || !keys.every((key) => typeof key === 'string' && key !== '')) {
      return refuse('Send {"keys": [...]} with 1 to 100 keys.');
    }
    const found = await kv.get(keys, 'text');
    return Response.json({ values: Object.fromEntries(keys.map((key) => [key, found.get(key) ?? null])) });
  }
  if (path === '') return refuse('Name a key.');
  if (request.method === 'GET') {
    const cacheTtl = Number(request.headers.get('x-dply-cache-ttl') || 0);
    if (cacheTtl !== 0 && !(Number.isInteger(cacheTtl) && cacheTtl >= 30)) return refuse('x-dply-cache-ttl must be 30 seconds or more.');
    const { value, metadata } = await kv.getWithMetadata(path, cacheTtl ? { type: 'text', cacheTtl } : { type: 'text' });
    const headers = { 'content-type': 'text/plain; charset=utf-8' };
    if (metadata != null) headers['x-dply-metadata'] = asciiJson(metadata);
    return new Response(value, { status: value == null ? 404 : 200, headers });
  }
  if (request.method === 'PUT') {
    const ttl = request.headers.get('x-dply-ttl');
    const expiresAt = request.headers.get('x-dply-expires-at');
    const metadata = request.headers.get('x-dply-metadata');
    if (ttl && expiresAt) return refuse('Send x-dply-ttl or x-dply-expires-at, not both.');
    const options = {};
    if (Number(ttl) >= 60) options.expirationTtl = Math.floor(Number(ttl));
    if (expiresAt) {
      const at = Number(expiresAt);
      if (!Number.isInteger(at) || at < Date.now() / 1000 + 60) return refuse('x-dply-expires-at must be a unix time at least 60 seconds ahead.');
      options.expiration = at;
    }
    if (metadata !== null) {
      try { options.metadata = JSON.parse(metadata); } catch { return refuse('x-dply-metadata must be JSON.'); }
      if (new TextEncoder().encode(JSON.stringify(options.metadata)).length > 1024) return refuse('x-dply-metadata must be 1024 bytes or less.');
    }
    await kv.put(path, await request.arrayBuffer(), options);
    return new Response(null, { status: 204 });
  }
  if (request.method === 'DELETE') {
    await kv.delete(path);
    return new Response(null, { status: 204 });
  }
  return new Response('Method not allowed.', { status: 405 });
}

async function connectionFetch(c, request, env, ctx) {
  if (c.asleep) return new Response('This resource is asleep.', { status: 503 });
  // AI and vector search go through dply's meter (EdgeMeter): cap, kill switch, usage.
  const binding = c.kind === 'ai' || c.kind === 'vectors' ? dplyMetered(c.kind, env[c.name], env, ctx, c.name) : env[c.name];
  const url = new URL(request.url);
  const path = decodeURIComponent(url.pathname.replace(/^\//, ''));
  const json = async () => request.headers.get('content-type')?.includes('json') ? request.json() : {};
  if (c.kind === 'durable_object') {
    return binding.get(binding.idFromName('store')).fetch(request);
  }
  if (c.kind === 'key_value') return kvFetch(binding, request, path, url);
  if (c.kind === 'object_storage') {
    if (request.method === 'GET' && path === '') {
      const listed = await binding.list({ limit: 100 });
      return Response.json({ objects: (listed.objects || []).map((object) => ({ key: object.key, size: object.size })) });
    }
    if (path === '') return new Response('Name an object.', { status: 400 });
    if (request.method === 'GET') {
      const value = await binding.get(path);
      if (value == null) return new Response(null, { status: 404 });
      const headers = new Headers();
      if (value.httpMetadata?.contentType) headers.set('content-type', value.httpMetadata.contentType);
      return new Response(value.body, { status: 200, headers });
    }
    if (request.method === 'PUT') {
      await binding.put(path, await request.arrayBuffer(), { httpMetadata: { contentType: request.headers.get('content-type') || 'application/octet-stream' } });
      return new Response(null, { status: 204 });
    }
    if (request.method === 'DELETE') { await binding.delete(path); return new Response(null, { status: 204 }); }
  }
  if (c.kind === 'sql' && request.method === 'POST') {
    const body = await json();
    return Response.json(await binding.prepare(body.sql).bind(...(body.params || [])).all());
  }
  if (c.kind === 'queue' && request.method === 'POST') { await binding.send(await request.text()); return new Response(null, { status: 202 }); }
  if (c.kind === 'ai' && request.method === 'POST') { const body = await json(); return Response.json(await binding.run(body.model, body.input)); }
  if (c.kind === 'vectors' && request.method === 'POST') { const body = await json(); return Response.json(await binding.query(body.vector, { topK: body.topK || 5 })); }
  if (c.kind === 'images' && request.method === 'POST') {
    const bytes = await request.arrayBuffer();
    if (path === 'info') return Response.json(await binding.info(bytes));
    const q = url.searchParams;
    const transform = {};
    const width = Number(q.get('width') || 0);
    const height = Number(q.get('height') || 0);
    if (width > 0 && width <= 8000) transform.width = Math.floor(width);
    if (height > 0 && height <= 8000) transform.height = Math.floor(height);
    const fit = q.get('fit');
    if (['scale-down', 'contain', 'cover', 'crop', 'pad'].includes(fit)) transform.fit = fit;
    const formats = { jpeg: 'image/jpeg', jpg: 'image/jpeg', png: 'image/png', webp: 'image/webp', avif: 'image/avif', gif: 'image/gif' };
    const format = formats[String(q.get('format') || 'webp')] || 'image/webp';
    const quality = Number(q.get('quality') || 0);
    const output = { format };
    if (quality >= 1 && quality <= 100) output.quality = Math.floor(quality);
    const image = binding.input(new Response(bytes).body);
    const transformed = Object.keys(transform).length ? image.transform(transform) : image;
    return (await transformed.output(output)).response();
  }
  if (c.kind === 'workflow' && request.method === 'POST') { const body = await json(); return Response.json(await binding.create({ id: body.id, params: body.params })); }
  if (c.kind === 'database_pool' && request.method === 'GET') return Response.json({ connectionString: binding.connectionString });
  if (c.kind === 'service') {
    if (!env.DISPATCHER || !c.script || !c.origin) return new Response('This app is not connected.', { status: 404 });
    const incoming = new URL(request.url);
    const target = new URL(c.origin);
    target.pathname = incoming.pathname;
    target.search = incoming.search;
    return env.DISPATCHER.get(c.script).fetch(new Request(target, request));
  }
  return new Response('This connection does not accept that request.', { status: 405 });
}

export class EdgeState extends DurableObject {
  async fetch(request) {
    const path = decodeURIComponent(new URL(request.url).pathname.replace(/^\//, ''));
    if (request.method === 'GET' && path === '') {
      const listed = await this.ctx.storage.list({ limit: 100 });
      return Response.json({ keys: [...listed.keys()] });
    }
    if (path === '') return new Response('Name a key.', { status: 400 });
    if (request.method === 'POST' && path.startsWith('incr/')) {
      const key = path.slice(5);
      const current = Number(await this.ctx.storage.get(key) ?? 0);
      const next = Number.isFinite(current) ? current + 1 : 1;
      await this.ctx.storage.put(key, String(next));
      return new Response(String(next), { headers: { 'content-type': 'text/plain; charset=utf-8' } });
    }
    if (request.method === 'GET') {
      const value = await this.ctx.storage.get(path);
      return new Response(value == null ? null : String(value), { status: value == null ? 404 : 200, headers: { 'content-type': 'text/plain; charset=utf-8' } });
    }
    if (request.method === 'PUT') {
      await this.ctx.storage.put(path, await request.text());
      return new Response(null, { status: 204 });
    }
    if (request.method === 'DELETE') {
      await this.ctx.storage.delete(path);
      return new Response(null, { status: 204 });
    }
    return new Response('This connection does not accept that request.', { status: 405 });
  }
}

__BROWSER_FETCH__

const INSTANCES = __INSTANCES__;
const MIN_INSTANCES = __MIN_INSTANCES__;
const CAPACITY = __CAPACITY__;
const SCHEDULES = __SCHEDULES__;
const JOBS_ALWAYS_ON = __JOBS_ALWAYS_ON__;

// Scaling windows (scheduled autoscaling). The most specific window that
// covers now wins: a date, then one weekday, then weekdays/weekends, then
// daily. Outside every window the defaults apply.
const DAY_RANK = { daily: 0, weekdays: 1, weekends: 1 };
const DATE = /^\d{4}-\d{2}-\d{2}$/;

function limits(now = new Date()) {
  let best = null;
  let bestRank = -1;
  for (const w of SCHEDULES) {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', {
      timeZone: w.timezone, weekday: 'short', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(now).map((p) => [p.type, p.value]));
    const day = parts.weekday.toLowerCase();
    const weekend = day === 'sat' || day === 'sun';
    const date = parts.year + '-' + parts.month + '-' + parts.day;
    const onDay = w.days === 'daily' || w.days === day || w.days === date || (w.days === 'weekdays' && !weekend) || (w.days === 'weekends' && weekend);
    const time = parts.hour + ':' + parts.minute;
    const rank = DATE.test(w.days) ? 3 : (DAY_RANK[w.days] ?? 2);
    if (onDay && time >= w.start && time < w.end && rank > bestRank) {
      best = w;
      bestRank = rank;
    }
  }
  return best ? { min: best.min, max: best.max } : { min: MIN_INSTANCES, max: INSTANCES };
}
const STICKY = __STICKY__;
const DEDICATED_JOBS = __DEDICATED_JOBS__;
const PAUSE_KEY = __PAUSE_KEY__;

function stickyId(request) {
  const match = (request.headers.get('cookie') ?? '').match(/(?:^|;\s*)dply_instance=(\d+)/);
  if (match) {
    const id = Number(match[1]);
    if (id >= 0 && id < limits().max) return id;
  }
  return null;
}

function instance(env, index) {
  return getContainer(env.APP, 'instance-' + index);
}

// ponytail: every request probes instance-0 first, so its DO sets the
// ceiling on requests per second; start from the last index with room if
// that ever shows up.
// First instance with room. All full: a random one, since every instance is
// already running and the cap is reached.
async function leastIndex(env) {
  const max = limits().max;
  // One instance: nothing to choose, so skip the hasRoom round trip.
  if (max === 1) return 0;
  for (let i = 0; i < max; i++) {
    if (await instance(env, i).hasRoom(i)) return i;
  }
  return Math.floor(Math.random() * max);
}

async function webTarget(env, request) {
  const existing = STICKY ? stickyId(request) : null;
  const index = existing ?? (await leastIndex(env));
  return { container: instance(env, index), cookie: STICKY && existing === null ? String(index) : null };
}

async function jobsTarget(env) {
  if (!DEDICATED_JOBS) return { container: instance(env, await leastIndex(env)), cookie: null };
  const container = getContainer(env.APP, 'jobs');
  await container.remember('jobs');
  return { container, cookie: null };
}

// Ask the app to run one scheduled task (POST /_dply/schedule), where the
// jobs run. The every-minute Laravel scheduler wakes the app only when one
// of its own tasks is due (the app reports their crons after each run), so
// an app with a nightly task sleeps the rest of the day.
async function runScheduled(env, cron, handler, at) {
  const plans = handler === 'schedule:run' && cron === '* * * * *' ? getContainer(env.APP, 'dply-schedule') : null;
  if (plans) {
    const saved = await plans.schedulePlan();
    const trusted = saved && saved.build === BUILD_ID && Date.now() - saved.at < 86400000 && Array.isArray(saved.plan);
    if (trusted && !saved.plan.some((p) => cronDue(p.cron, p.tz, at))) return;
  }
  const response = await proxy(env, new Request('http://app' + __SCHEDULE_PATH__, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-dply-queue-token': env.DPLY_QUEUE_TOKEN },
    body: JSON.stringify({ cron, handler }),
  }), await jobsTarget(env));
  if (plans) {
    const body = await response.clone().json().catch(() => ({}));
    // No plan (sub-minute tasks, an older dply/laravel): keep waking every minute.
    await plans.saveSchedulePlan({ build: BUILD_ID, at: Date.now(), plan: Array.isArray(body.plan) ? body.plan : null });
  }
}

// Start whatever should be running now: min instances (from the current
// window) and an always-on jobs instance. dply calls this after each deploy
// and every few minutes, which also brings back one Cloudflare restarted.
async function warm(env) {
  if (!(await trafficOpen(env))) return;
  const targets = Array.from({ length: limits().min }, (_, i) => [instance(env, i), i]);
  if (JOBS_ALWAYS_ON) targets.push([getContainer(env.APP, 'jobs'), 'jobs']);
  const workers = WORKER_GROUPS.flatMap((g) => workerNames(g).map((name) => [name, g]));
  await Promise.all([
    ...targets.map(async ([container, index]) => {
      await container.remember(index);
      await container.startAndWaitForPorts({ ports: [__PORT__], cancellationOptions: { portReadyTimeoutMS: 45000 } });
    }),
    // Without autoscaling every worker is always on (this also clears a
    // flag left from when the app autoscaled).
    ...workers.map(([name, g]) => g.autoscale ? getContainer(env.APP, name).resumeWorker(name) : getContainer(env.APP, name).startWorker(name)),
  ]);
}

// A WebSocket answer (101) passes through untouched: a new Response cannot
// carry status 101 or the socket. Sockets still stick: webTarget routes by
// the cookie the page load already set, or picks an instance.
function isSocket(response) {
  return response.status === 101 || Boolean(response.webSocket);
}

// Content-addressed file names (app.3f9a1c2e.css, app-BXa3Kq9z.js, …).
// Same rules as isImmutableAsset in packages/edge-worker/src/handler.ts.
function isImmutableAsset(path) {
  if (/\.[a-f0-9]{8,}\.[a-z0-9]+$/i.test(path)) return true;
  if (/(^|\/)(_next\/static|_astro|_app\/immutable)\//.test(path)) return true;
  const match = /[-.]([a-f0-9]{8,}|[A-Za-z0-9_-]{8})\.(?:m?js|css|woff2?|png|jpe?g|webp|avif|svg|gif|ico|wasm|map)$/.exec(path);
  if (!match) return false;
  return /\d/.test(match[1]) || (/[A-Z]/.test(match[1].slice(1)) && /[a-z]/.test(match[1]));
}

function withStickyCookie(response, id) {
  if (isSocket(response)) return response;
  const headers = new Headers(response.headers);
  headers.append('set-cookie', 'dply_instance=' + id + '; Path=/; HttpOnly; SameSite=Lax; Max-Age=604800');
  return new Response(response.body, { status: response.status, statusText: response.statusText, headers });
}

async function trafficOpen(env) {
  if (!env.BILLING) return true;
  try {
    return (await env.BILLING.get(PAUSE_KEY)) !== '1';
  } catch {
    return true;
  }
}

// A rollout or a cold start can exit the process before the port is open.
// container.fetch turns that into a 500 ("not running, consider calling start()")
// on the first try. Start again and give FrankenPHP time to listen.
function httpRequest(request, body) {
  const url = new URL(request.url);
  url.protocol = 'http:';
  const init = {
    method: request.method,
    headers: new Headers(request.headers),
    redirect: 'manual',
  };
  if (request.method !== 'GET' && request.method !== 'HEAD') init.body = body ?? request.body;
  return new Request(url, init);
}

async function proxy(env, request, target) {
  // A retry needs the body again, and a stream can only be read once: buffer
  // small bodies (forms, JSON, dply's own commands); stream large uploads and
  // do not retry them.
  const hasBody = request.method !== 'GET' && request.method !== 'HEAD';
  const retryable = !hasBody || Number(request.headers.get('content-length') ?? Infinity) <= 1048576;
  const body = hasBody && retryable ? await request.arrayBuffer() : undefined;
  // The public URL stays HTTPS. The container only accepts HTTP on this hop.
  const fresh = () => httpRequest(request, body);
  request = fresh();
  const container = target.container;
  // The warm path is one fetch. The SDK's containerFetch (0.3.x) starts the
  // container and waits for the port itself when it is not running or not
  // marked healthy. startAndWaitForPorts here on every request cost a DO
  // round trip, a storage write and a probe of the app's own / (waitForPort).
  // Only a large upload, which the retry loop below cannot replay, still
  // waits up front with the longer cold-start timeout.
  if (!retryable) {
    try {
      await container.startAndWaitForPorts({
        ports: [__PORT__],
        cancellationOptions: { portReadyTimeoutMS: 45000 },
      });
    } catch {
      // fetch() below starts the container again.
    }
  }
  // A WebSocket upgrade goes through here too, Upgrade / Sec-WebSocket-*
  // headers intact (httpRequest copies them). A 101 never enters the retry
  // loop; the SDK's 5xx for an upgrade come before any socket exists (start
  // failed, connection lost), so retrying one does not replay a live socket.
  let response = await container.fetch(request);
  for (let attempt = 0; retryable && attempt < 2 && response.status >= 500; attempt++) {
    const preview = await response.clone().text();
    if (!/not running|Failed to start container|Container crashed|suddenly disconnected/.test(preview)) {
      return countReply(env, revealAppErrors(env, response));
    }
    try {
      await container.startAndWaitForPorts({
        ports: [__PORT__],
        cancellationOptions: { portReadyTimeoutMS: 45000 },
      });
    } catch {
      // fetch() below starts the container again.
    }
    response = await container.fetch(fresh());
  }
  if (target.cookie !== null) response = withStickyCookie(response, target.cookie);
  return countReply(env, revealAppErrors(env, response));
}

// Bytes the container sent back as this reply, to Analytics Engine. Billing
// subtracts them from the container's tx_bytes, so only its own outbound
// traffic (APIs, S3) is charged. A reply with Content-Length is counted from
// the header and passes through untouched (a piped body would lose the
// header); a chunked one is counted as it streams. A socket (101) cannot be
// wrapped, and a stream the client drops (SSE, aborted download) never
// reaches flush(), so those bytes count as outbound.
function countReply(env, response) {
  if (!env.DPLY_BYTES || !response.body || isSocket(response)) return response;
  const record = (bytes) => {
    try {
      env.DPLY_BYTES.writeDataPoint({ indexes: [SITE_ID], blobs: [SITE_ID], doubles: [bytes] });
    } catch {
      // Metering never breaks a reply.
    }
  };
  const length = Number(response.headers.get('content-length'));
  if (Number.isFinite(length) && length > 0) {
    record(length);
    return response;
  }
  let bytes = 0;
  const counter = new TransformStream({
    transform(chunk, controller) {
      bytes += chunk.byteLength;
      controller.enqueue(chunk);
    },
    flush() {
      record(bytes);
    },
  });
  return new Response(response.body.pipeThrough(counter), response);
}

function revealAppErrors(env, response) {
  const flag = String(env.APP_DEBUG ?? '').trim().toLowerCase();
  if (isSocket(response) || (flag !== 'true' && flag !== '1' && flag !== '(true)')) return response;
  const headers = new Headers(response.headers);
  headers.set('x-dply-app-debug', '1');
  return new Response(response.body, { status: response.status, statusText: response.statusText, headers });
}

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    if (url.pathname.startsWith('/_dply/')) {
      if (request.headers.get('x-dply-queue-token') !== env.DPLY_QUEUE_TOKEN) {
        return new Response('Forbidden', { status: 403 });
      }
      if (url.pathname === '/_dply/warm' && request.method === 'POST') {
        ctx.waitUntil(warm(env));
        return new Response(null, { status: 202 });
      }
      // Web instance state (running or not), for sampling only awake apps.
      if (url.pathname === '/_dply/instances' && request.method === 'GET') {
        const names = Array.from({ length: INSTANCES }, (_, i) => 'instance-' + i);
        return Response.json(await Promise.all(names.map(async (name) => {
          const container = getContainer(env.APP, name);
          return { name, ...(await container.getState()), lastActivity: await container.activity().catch(() => null) };
        })));
      }
      // Queue worker state for the workspace. Reading it never starts one.
      if (url.pathname === '/_dply/workers' && request.method === 'GET') {
        const names = allWorkerNames();
        return Response.json(await Promise.all(names.map(async (name) => ({ name, group: workerGroup(name)?.group.key ?? '', ...(await getContainer(env.APP, name).workerState(name)) }))));
      }
      if (url.pathname === '/_dply/workers/pause' && request.method === 'POST') {
        const { paused = true } = await request.json();
        const names = allWorkerNames();
        return Response.json(await Promise.all(names.map(async (name) => {
          try {
            await getContainer(env.APP, name).pauseWorker(name, Boolean(paused));
            return { name, ok: true };
          } catch (e) {
            return { name, ok: false, error: String(e && e.message ? e.message : e) };
          }
        })));
      }
      if (url.pathname === '/_dply/replace' && request.method === 'POST') {
        const { index = 0 } = await request.json();
        try {
          await instance(env, Number(index) || 0).replace(Number(index) || 0);
          return Response.json({ ok: true });
        } catch (e) {
          return Response.json({ ok: false, error: String(e && e.message ? e.message : e) }, { status: 500 });
        }
      }
      // The autoscaler: run a group's first `count` workers, stop the rest.
      if (url.pathname === '/_dply/workers/scale' && request.method === 'POST') {
        const { count = 0, group = '' } = await request.json();
        const g = WORKER_GROUPS.find((x) => x.key === group);
        if (!g) return Response.json({ error: `No worker group ${group}` }, { status: 404 });
        const want = Math.max(g.min, Math.min(g.max, Number(count) || 0));
        const names = workerNames(g);
        return Response.json(await Promise.all(names.map(async (name, i) => {
          try {
            const c = getContainer(env.APP, name);
            await (i < want ? c.startWorker(name) : c.stopWorker(name));
            return { name, wanted: i < want, ok: true };
          } catch (e) {
            return { name, wanted: i < want, ok: false, error: String(e && e.message ? e.message : e) };
          }
        })));
      }
      // Start the workers now and say what happened to each (warm does it in the background).
      if (url.pathname === '/_dply/workers/start' && request.method === 'POST') {
        const names = allWorkerNames();
        return Response.json(await Promise.all(names.map(async (name) => {
          try {
            await getContainer(env.APP, name).resumeWorker(name);
            return { name, ok: true };
          } catch (e) {
            return { name, ok: false, error: String(e && e.message ? e.message : e) };
          }
        })));
      }
      // The Images sheet's demo: the same path the app's calls to images.internal take.
      if ((url.pathname === '/_dply/images' || url.pathname === '/_dply/images/info') && request.method === 'POST') {
        const images = CONNECTIONS.find((c) => c.kind === 'images');
        if (!images) return new Response('Images is not attached to this app.', { status: 404 });
        const target = new URL(request.url);
        target.pathname = url.pathname.endsWith('/info') ? '/info' : '/';
        return connectionFetch(images, new Request(target, request), env, ctx).catch(dplyRefusal);
      }
      // The Vector search sheet's demo: {host, vector, topK}, searched the way the app's /query calls are (metered).
      if (url.pathname === '/_dply/vectors' && request.method === 'POST') {
        const body = await request.json();
        const index = CONNECTIONS.find((c) => c.kind === 'vectors' && c.host === body.host);
        if (!index) return new Response('That index is not attached to this app.', { status: 404 });
        const query = new Request(new URL('/query', request.url), { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ vector: body.vector, topK: body.topK || 5 }) });
        return connectionFetch(index, query, env, ctx).catch(dplyRefusal);
      }
      if (url.pathname === '/_dply/command' && request.method === 'POST') {
        return proxy(env, request, await webTarget(env, request));
      }
      // "Run now" from the dashboard: same place the Cron Trigger runs it.
      if (url.pathname === __SCHEDULE_PATH__ && request.method === 'POST') {
        return proxy(env, request, await jobsTarget(env));
      }
      if (url.pathname === __QUEUE_SEND_PATH__ && request.method === 'POST') {
        const { queue = 'JOBS', body, delay = 0 } = await request.json();
        const producer = env[queue];
        if (!producer || typeof producer.send !== 'function') {
          return Response.json({ error: `No queue binding named ${queue}` }, { status: 404 });
        }
        await producer.send(body, { contentType: 'json', delaySeconds: Math.min(Math.max(0, delay), 43200) });
        return new Response(null, { status: 202 });
      }
      return new Response('Not found', { status: 404 });
    }

    if ((request.method === 'GET' || request.method === 'HEAD') && env.ASSETS && /\.(css|js|mjs|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot|txt|xml|webmanifest)$/i.test(url.pathname)) {
      const asset = await env.ASSETS.fetch(request);
      // Public build output. An app reached on a custom domain may still link
      // its assets on another host (ASSET_URL), and module scripts need CORS.
      if (asset.status !== 404) {
        const headers = new Headers(asset.headers);
        headers.set('access-control-allow-origin', '*');
        return new Response(asset.body, { status: asset.status, statusText: asset.statusText, headers });
      }
    }

    // Fingerprinted build output the image built (Vite's public/build is not
    // in the repo, so ASSETS misses it) comes from the container once, then
    // from this colo's cache.
    const immutable = request.method === 'GET' && isImmutableAsset(url.pathname);
    if (immutable) {
      // Best effort: a cache that is missing or refuses (dispatch-namespace
      // scripts can lack one) must never fail the request.
      try {
        const hit = await caches.default.match(request);
        if (hit) return hit;
      } catch {}
    }

    if (!(await trafficOpen(env))) {
      return new Response('This app is paused. The workspace usage credit is used up.', { status: 503, headers: { 'content-type': 'text/plain; charset=utf-8', 'retry-after': '3600' } });
    }
    const headers = new Headers(request.headers);
    headers.set('x-forwarded-proto', url.protocol.replace(':', ''));
    headers.set('x-forwarded-host', url.host);
    const response = await proxy(env, new Request(request, { headers }), await webTarget(env, request));
    if (!immutable || response.status !== 200 || response.headers.has('set-cookie')) return response;
    const cacheable = new Headers(response.headers);
    cacheable.set('cache-control', 'public, max-age=31536000, immutable');
    cacheable.set('access-control-allow-origin', '*');
    const stored = new Response(response.body, { status: 200, statusText: response.statusText, headers: cacheable });
    try {
      ctx.waitUntil(caches.default.put(request, stored.clone()).catch(() => {}));
    } catch {}
    return stored;
  },

  // One Cron Trigger, every minute (no 5-schedule limit): run each task whose
  // schedule is due now, in UTC. Nothing due means nothing wakes.
  async scheduled(controller, env, ctx) {
    if (!(await trafficOpen(env))) return;
    const at = new Date(controller.scheduledTime);
    for (const [cron, handlers] of Object.entries(CRON_HANDLERS)) {
      if (cron === '* * * * *' || cronDue(cron, 'UTC', at, true)) {
        for (const handler of handlers) ctx.waitUntil(runScheduled(env, cron, handler, at));
      }
    }
  },

  // Push each batch into the app; it answers { failed: [message ids] }.
  async queue(batch, env) {
    if (!(await trafficOpen(env))) {
      batch.retryAll({ delaySeconds: 3600 });
      return;
    }
    const response = await proxy(env, new Request('http://app' + __QUEUE_PATH__, {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'x-dply-queue-token': env.DPLY_QUEUE_TOKEN },
      body: JSON.stringify({
        queue: QUEUE_BINDINGS[batch.queue] ?? batch.queue,
        messages: batch.messages.map((m) => ({ id: m.id, body: m.body, attempts: m.attempts })),
      }),
    }), await jobsTarget(env));
    if (!response.ok) {
      batch.retryAll({ delaySeconds: 30 });
      return;
    }
    const { failed = [] } = await response.json().catch(() => ({}));
    for (const message of batch.messages) {
      failed.includes(message.id) ? message.retry({ delaySeconds: 30 }) : message.ack();
    }
  },
};
JS, $replace);
    }

    /**
     * Scheduled tasks for the site: dashboard / dply.yaml entries (handler =
     * artisan command, rake task or shell command) plus `schedule:run` every
     * minute when the scheduler is on. They share one every-minute Cron
     * Trigger and the Worker runs whichever are due, so Cloudflare's
     * 5-schedule limit does not apply: up to EdgeCronExpression::MAX_TASKS,
     * and an expression the Worker cannot read is left out (the dashboard
     * marks it Won't run).
     *
     * @return array<string, list<?string>>
     */
    public static function cronHandlers(Site $site, ?EdgeDeployment $deployment): array
    {
        // Previews never run scheduled tasks — production already does.
        if ($site->isEdgePreview()) {
            return [];
        }

        $crons = [];
        // With queue workers the scheduler runs in worker-0 instead, so the
        // web container is not woken every minute.
        if (EdgeContainerSettings::for($site)['scheduler'] && ! EdgeQueueWorkers::runsScheduler($site)) {
            $crons['* * * * *'][] = 'schedule:run';
        }
        $tasks = 0;
        foreach (EdgeEffectiveCrons::for($site, $deployment) as $cron) {
            if ($tasks >= EdgeCronExpression::MAX_TASKS || ! EdgeCronExpression::supported($cron['schedule'])) {
                continue;
            }
            $crons[$cron['schedule']][] = $cron['handler'];
            $tasks++;
        }

        return $crons;
    }

    /**
     * Queue bindings (dashboard + repo) as binding name => queue name.
     *
     * @return array<string, string>
     */
    private function queueBindings(Site $site, ?EdgeDeployment $deployment): array
    {
        $out = [];
        foreach (EdgeEffectiveBindings::for($site, $deployment) as $binding) {
            if ($binding['kind'] === 'queue' && $binding['value'] !== '') {
                // The account is shared: a repo may only bind this org's queues.
                if ($binding['source'] === 'repo' && ($site->organization === null || ! EdgeContainerConnections::owns('queue', $binding['value'], $site->organization))) {
                    throw new RuntimeException(sprintf('wrangler.toml binding %s (%s): it is not a queue this organization owns.', $binding['name'], $binding['value']));
                }
                $out[$binding['name']] = $binding['value'];
            }
        }

        return $out;
    }

    /**
     * Recent failure text plus Workers Logs, so a deploy can grow the
     * instance when the last one was OOM-killed. Log access is best-effort.
     */
    private function memoryEvidence(Site $site): string
    {
        $chunks = [];
        $last = EdgeDeployment::query()->where('site_id', $site->id)->latest('created_at')->first();
        if ($last !== null) {
            $chunks[] = (string) $last->failure_reason;
            $chunks[] = (string) data_get($last->meta, 'container.health.error');
        }

        try {
            foreach (EdgeCloudflareClient::fromConfig()->workerLogs(self::scriptName($site), 30, 40) as $row) {
                $chunks[] = $row['message'];
            }
        } catch (Throwable) {
            // A token without the observability scope must not fail the deploy.
        }

        return implode("\n", $chunks);
    }

    /** @param callable(string): void $log */
    private function persistInstanceFloor(Site $site, string $resolved, callable $log): void
    {
        $raw = is_array($site->edgeMeta()['container'] ?? null) ? $site->edgeMeta()['container'] : [];
        if (($raw['instance_type'] ?? '') === $resolved) {
            return;
        }

        $site->mergeEdgeMeta(['container' => array_merge($raw, ['instance_type' => $resolved])]);
        $site->save();
        $log("Instance size set to {$resolved} so the app stays within memory.\n");
    }

    private function billingKvNamespaceId(Site $site): string
    {
        $context = app(EdgeDeliveryContextResolver::class)->forSite($site);
        if (! $context->isPlatform() || $context->kvNamespaceId === '') {
            return '';
        }

        return $context->kvNamespaceId;
    }

    /**
     * The deployer `docker run`. Only trusted code runs in it (wrangler and
     * the scaffold's pinned deps, install scripts off) next to the socket and
     * token; the customer's Dockerfile goes to the BuildKit builder, where
     * RUN steps get neither. See docs/edge-build-isolation.md.
     *
     * @return list<string>
     */
    public static function deployerCommand(string $name, string $workRoot, string $project, string $namespace, string $rolloutMode): array
    {
        $builder = trim((string) config('edge.build.containers.builder', ''));
        $token = trim((string) config('edge.build.containers.deploy_api_token', ''));

        return [
            // Named so cancelling can kill it: the container outlives this
            // client, and an abandoned one keeps building and pushing.
            'docker', 'run', '--rm', '--name', $name,
            '-v', '/var/run/docker.sock:/var/run/docker.sock',
            // Same absolute path inside the deployer so the Dockerfile path in
            // wrangler.jsonc resolves.
            '-v', $workRoot.':'.$workRoot,
            '-w', $project,
            '-e', 'CLOUDFLARE_API_TOKEN='.($token !== '' ? $token : (string) config('edge.cloudflare.api_token')),
            '-e', 'CLOUDFLARE_ACCOUNT_ID='.config('edge.cloudflare.account_id'),
            '-e', 'WRANGLER_SEND_METRICS=false',
            // Without this buildx uses TTY progress: it rewrites the same lines
            // in place and batches when stdout isn't a terminal, so a live build
            // looks frozen in the log. Plain mode appends one line per event.
            '-e', 'BUILDKIT_PROGRESS=plain',
            // wrangler runs `docker build`, which buildx routes to this builder.
            ...($builder !== '' ? ['-e', 'BUILDX_BUILDER='.$builder] : []),
            (string) config('edge.build.containers.deployer_image'),
            'sh', '-c', self::deployerScript($builder), $namespace, $rolloutMode,
        ];
    }

    /**
     * Create the docker-container builder on the build network if this
     * (throwaway) client doesn't know it; an existing builder container is
     * reused, so the driver opts only take effect when it is first created.
     * `&&`: no builder means no deploy, never a silent host-daemon build.
     */
    public static function deployerScript(string $builder): string
    {
        $deploy = 'npm install --silent --no-audit --no-fund --ignore-scripts && wrangler deploy --dispatch-namespace "$0" --secrets-file secrets.json --containers-rollout "$1"';
        if ($builder === '') {
            return $deploy;
        }

        $opts = ['image='.(string) config('edge.build.containers.builder_image', 'moby/buildkit:v0.32.2')];
        $network = trim((string) config('edge.build.sandbox.network', ''));
        if ($network !== '') {
            $opts[] = 'network='.$network;
        }
        $memory = trim((string) config('edge.build.containers.builder_memory', ''));
        if ($memory !== '') {
            array_push($opts, 'memory='.$memory, 'memory-swap='.$memory);
        }
        $cpus = (float) config('edge.build.containers.builder_cpus', 0);
        if ($cpus > 0) {
            array_push($opts, 'cpu-period=100000', 'cpu-quota='.(int) round($cpus * 100000));
        }

        $create = 'docker buildx create --name '.escapeshellarg($builder).' --driver docker-container';
        foreach ($opts as $opt) {
            $create .= ' --driver-opt '.escapeshellarg($opt);
        }

        return '{ docker buildx inspect '.escapeshellarg($builder).' >/dev/null 2>&1 || '.$create.' >/dev/null; } && '.$deploy;
    }

    private static function ensureBuilderNetwork(): void
    {
        $network = trim((string) config('edge.build.sandbox.network', ''));
        if (trim((string) config('edge.build.containers.builder', '')) !== '' && $network !== '') {
            EdgeBuildRunner::ensureBuildNetwork($network, (array) config('edge.build.sandbox', []));
        }
    }

    /** Per-org, unguessable prefix for BuildKit cache mount ids. */
    public static function cacheScope(Site $site): string
    {
        return substr(hash_hmac('sha256', 'container-build-cache:'.(string) $site->organization_id, (string) config('app.key')), 0, 16);
    }

    /**
     * BuildKit cache mounts are keyed by id (default: the target path) across
     * every build on the builder, so one org's `RUN --mount=type=cache` could
     * poison another's npm/composer cache — including explicit ids copied
     * from docs (`id=pnpm`). Prefix every cache id with the org scope.
     */
    public static function scopeCacheMounts(string $dockerfile, string $scope): string
    {
        return preg_replace_callback('/--mount=(\S+)/', static function (array $m) use ($scope): string {
            $opts = [];
            foreach (explode(',', $m[1]) as $part) {
                [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
                $opts[strtolower($key)] = $value;
            }
            $id = $opts['id'] ?? $opts['target'] ?? $opts['dst'] ?? $opts['destination'] ?? '';
            if (($opts['type'] ?? '') !== 'cache' || $id === '' || str_starts_with($id, 'dply-'.$scope)) {
                return $m[0];
            }
            $rest = array_filter(explode(',', $m[1]), static fn (string $part): bool => strtolower(explode('=', $part, 2)[0]) !== 'id');

            return '--mount=id=dply-'.$scope.'-'.ltrim($id, '/').','.implode(',', $rest);
        }, $dockerfile) ?? $dockerfile;
    }

    /** @param callable(string): void $log */
    public function ensureDeployerImage(callable $log): void
    {
        $image = (string) config('edge.build.containers.deployer_image');
        if (Process::run(['docker', 'image', 'inspect', $image])->successful()) {
            $log("Deployer image {$image} is ready.\n");

            return;
        }

        $log("Building deployer image {$image}…\n");
        $build = Process::timeout(900)->run(['docker', 'build', '-t', $image, base_path('docker/edge-container-deployer')]);
        if (! $build->successful()) {
            throw new RuntimeException('Could not build the container deployer image: '.trim(substr($build->errorOutput(), -400)));
        }
    }
}
