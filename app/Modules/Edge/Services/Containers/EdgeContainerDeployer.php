<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Containers;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Billing\Services\StarterTrafficGate;
use App\Modules\Edge\Services\EdgeDeliveryContextResolver;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeEffectiveBindings;
use App\Modules\Edge\Support\EdgeEffectiveCrons;
use App\Modules\Edge\Support\EdgeLogCopy;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Deploys a container site: generates a small Worker project (a
 * `@cloudflare/containers` class fronting the app image, a queue consumer that
 * pushes batches into the app, and a queue producer endpoint the app calls),
 * then runs `wrangler deploy --dispatch-namespace` in the deployer image
 * against the host Docker socket. wrangler builds and pushes the image and
 * rolls the container out.
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

    public const QUEUE_PATH = '/_dply/queue';

    public const QUEUE_SEND_PATH = '/_dply/queue/send';

    public const SCHEDULE_PATH = '/_dply/schedule';

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

    /** Deterministic so CancelStuckEdgeDeployment can `docker kill` it. */
    public static function buildContainerName(EdgeDeployment $deployment): string
    {
        return 'dply-edge-build-'.strtolower((string) $deployment->id);
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
        $log(sprintf(
            "Container settings: %s, %d instance(s) (+1 deploy slot), sleep %s, rollout %s\n",
            $settings['instance_type'],
            $settings['max_instances'],
            $settings['sleep_after'],
            $settings['rollout_mode'],
        ));

        $broughtOwnDatabase = isset($env['DB_CONNECTION']) || isset($env['DB_URL']) || isset($env['DATABASE_URL']);
        $withDefaults = EdgeContainerEnvDefaults::ensure($site, $checkout, $env);
        $log(EdgeContainerEnvDefaults::describe($env, $withDefaults));
        $env = $withDefaults;
        $migrateOnBoot = $settings['migrate_on_boot'] || (! $broughtOwnDatabase && ($env['DB_CONNECTION'] ?? '') === 'sqlite');

        $project = $workRoot.'/container-worker';
        $queues = $this->queueBindings($site, $deployment);
        $this->scaffold($project, $site, $image['path'], $image['port'], $queues, self::cronHandlers($site, $deployment), $this->billingKvNamespaceId($site));
        if ($this->attachStaticAssets($project, $checkout, $site)) {
            $log("CSS, JavaScript, and images from public/ are served automatically.\n");
        }

        $queueEnv = EdgeContainerConnections::queueDriverEnv($site);
        if (! isset($queueEnv['DPLY_QUEUE']) && $queues !== []) {
            $queueEnv['DPLY_QUEUE'] = (string) array_key_first($queues);
            if ($site->isLaravelFrameworkDetected()) {
                $queueEnv['QUEUE_CONNECTION'] = 'dply';
            }
        }

        $env = EdgeContainerConnections::omitAsleepRedis($site, $env);
        File::put($project.'/secrets.json', json_encode(array_merge(EdgeContainerConnections::redisDriverEnv($site), EdgeContainerConnections::storageDriverEnv($site), EdgeContainerConnections::kvDriverEnv($site), $queueEnv, $env, [
            'DPLY_QUEUE_TOKEN' => self::queueToken($site),
            'DPLY_APP_URL' => (string) ($site->edgeLiveUrl() ?? ''),
            'DPLY_MIGRATE_ON_BOOT' => $migrateOnBoot ? '1' : '0',
        ]), JSON_THROW_ON_ERROR));

        $this->ensureDeployerImage($log);

        $namespace = (string) config('edge.cloudflare.dispatch_namespace_name');
        // wrangler goes quiet after the layer push while Cloudflare ingests the
        // image and rolls out the container — minutes, with no output at all.
        // Say so, or every deploy reads as a hang at exactly this point.
        $log("Building the image (npm, Vite, Composer) and pushing it. Docker output follows.\n");

        // Same absolute path inside the deployer so the Dockerfile path in
        // wrangler.jsonc resolves; the host socket does the actual build.
        $result = $this->runWithHeartbeat($log, Process::timeout($timeoutSeconds ?? 1800), [
            // Named so cancelling can kill it: the container outlives this
            // client, and an abandoned one keeps building and pushing.
            'docker', 'run', '--rm', '--name', self::buildContainerName($deployment),
            '-v', '/var/run/docker.sock:/var/run/docker.sock',
            '-v', $workRoot.':'.$workRoot,
            '-w', $project,
            '-e', 'CLOUDFLARE_API_TOKEN='.config('edge.cloudflare.api_token'),
            '-e', 'CLOUDFLARE_ACCOUNT_ID='.config('edge.cloudflare.account_id'),
            '-e', 'WRANGLER_SEND_METRICS=false',
            // Without this buildx uses TTY progress: it rewrites the same lines
            // in place and batches when stdout isn't a terminal, so a live build
            // looks frozen in the log. Plain mode appends one line per event.
            '-e', 'BUILDKIT_PROGRESS=plain',
            (string) config('edge.build.containers.deployer_image'),
            'sh', '-c', 'npm install --silent --no-audit --no-fund && wrangler deploy --dispatch-namespace "$0" --secrets-file secrets.json --containers-rollout "$1"',
            $namespace,
            $settings['rollout_mode'],
        ]);

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
        // answers. Any HTTP status is enough — a 500 is the app. A hang is not.
        $url = $site->edgeLiveUrl();
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Container deploy failed: the app has no live URL to check.');
        }
        $log("Checking {$url} answers.\n");
        try {
            $response = Http::timeout(90)->withoutRedirecting()->get($url);
        } catch (Throwable $e) {
            throw new RuntimeException("Container deploy failed: {$url} did not answer: ".$e->getMessage(), previous: $e);
        }
        $log(sprintf("App answered HTTP %d.\n", $response->status()));

        return [
            'script_name' => self::scriptName($site),
            'stack' => $image['stack'],
            'port' => $image['port'],
            'queues' => array_keys($queues),
            'rollout' => $rollout,
        ];
    }

    /**
     * Write the Worker project wrangler deploys.
     *
     * @param  array<string, string>  $queues  binding name => queue name
     * @param  array<string, list<?string>>  $crons  schedule => handlers (artisan command / rake task)
     */
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

    public function scaffold(string $dir, Site $site, string $dockerfile, int $port, array $queues, array $crons = [], string $kvNamespaceId = ''): void
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
                'max_instances' => EdgeContainerSettings::wranglerMaxInstances($settings['max_instances'], $settings['dedicated_jobs']),
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
            // Container tab through the telemetry query API.
            'observability' => ['enabled' => true],
        ];
        if ($queues !== []) {
            $config['queues'] = [
                'producers' => array_map(static fn (string $name, string $queue): array => ['binding' => $name, 'queue' => $queue], array_keys($queues), $queues),
            ];
            // A queue takes one consumer: only the production site processes
            // jobs; previews can enqueue but never steal production's messages.
            if (! $site->isEdgePreview()) {
                $config['queues']['consumers'] = array_map(static fn (string $queue): array => ['queue' => $queue, 'max_batch_size' => 10, 'max_retries' => 5], array_values($queues));
            }
        }

        if ($crons !== []) {
            $config['triggers'] = ['crons' => array_keys($crons)];
        }

        if ($kvNamespaceId !== '') {
            $config['kv_namespaces'] = [['binding' => 'BILLING', 'id' => $kvNamespaceId]];
        }

        $config = EdgeContainerConnections::mergeWrangler($config, $site);

        File::put($dir.'/wrangler.jsonc', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        File::put($dir.'/package.json', json_encode([
            'name' => self::scriptName($site),
            'private' => true,
            'type' => 'module',
            'dependencies' => EdgeContainerConnections::browserEnabled($site)
                ? ['@cloudflare/containers' => '^0', '@cloudflare/puppeteer' => '^1']
                : ['@cloudflare/containers' => '^0'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::put($dir.'/src/index.js', $this->workerSource($port, array_flip($queues), $settings, $crons, $site));
    }

    /**
     * @param  array<string, string>  $queueBindings  queue name => binding name
     * @param  array{instance_type: string, max_instances: int, sleep_after: string, migrate_on_boot: bool, jurisdiction: string, scheduler: bool}  $settings
     * @param  array<string, list<?string>>  $crons
     */
    private function workerSource(int $port, array $queueBindings, array $settings, array $crons, Site $site): string
    {
        $replace = [
            '__PORT__' => (string) $port,
            '__SLEEP__' => json_encode($settings['sleep_after']),
            '__INSTANCES__' => (string) $settings['max_instances'],
            '__STICKY__' => $settings['sticky_sessions'] ? 'true' : 'false',
            '__DEDICATED_JOBS__' => $settings['dedicated_jobs'] ? 'true' : 'false',
            '__FPM_CHILDREN__' => (string) EdgeContainerSettings::phpFpmPool($settings['instance_type'], $site)['max_children'],
            '__FPM_LIMIT__' => json_encode(EdgeContainerSettings::phpFpmPool($settings['instance_type'], $site)['memory_limit']),
            '__QUEUE_PATH__' => json_encode(self::QUEUE_PATH, JSON_UNESCAPED_SLASHES),
            '__QUEUE_SEND_PATH__' => json_encode(self::QUEUE_SEND_PATH, JSON_UNESCAPED_SLASHES),
            '__QUEUE_BINDINGS__' => json_encode((object) $queueBindings, JSON_UNESCAPED_SLASHES),
            '__SCHEDULE_PATH__' => json_encode(self::SCHEDULE_PATH, JSON_UNESCAPED_SLASHES),
            '__CRON_HANDLERS__' => json_encode((object) $crons, JSON_UNESCAPED_SLASHES),
            '__PAUSE_KEY__' => json_encode(StarterTrafficGate::KEY_PREFIX.$site->id),
            '__CONNECTIONS__' => json_encode($this->workerConnections($site), JSON_UNESCAPED_SLASHES),
            '__QSTASH_TOKEN__' => json_encode((string) config('edge.upstash.qstash_token')),
            '__DELIVERY_USAGE_URL__' => json_encode(rtrim((string) config('app.url'), '/').'/hooks/edge/'.$site->id.'/delivery'),
            '__CLIENT_CERT__' => json_encode(EdgeContainerConnections::clientCertificateId($site) !== '' ? 'CLIENT_CERT' : ''),
            '__BROWSER__' => EdgeContainerConnections::browserEnabled($site) ? 'true' : 'false',
            '__BROWSER_HOST__' => json_encode(EdgeContainerConnections::browserHost($site)),
            '__BROWSER_IMPORT__' => EdgeContainerConnections::browserEnabled($site)
                ? "import puppeteer from '@cloudflare/puppeteer';\n"
                : '',
            '__BROWSER_FETCH__' => EdgeContainerConnections::browserEnabled($site)
                ? <<<'JS'
async function browserFetch(request, env) {
  if (request.method !== 'POST') return new Response('Send {"url"} as JSON.', { status: 405 });
  const body = await request.json();
  if (!body.url) return new Response('Missing url.', { status: 400 });
  const browser = await puppeteer.launch(env.BROWSER);
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
import { Container, getContainer, getRandom } from '@cloudflare/containers';
import { DurableObject } from 'cloudflare:workers';
export { ContainerProxy } from '@cloudflare/containers';
__BROWSER_IMPORT__

const QUEUE_BINDINGS = __QUEUE_BINDINGS__; // queue name -> binding name
const CRON_HANDLERS = __CRON_HANDLERS__; // schedule -> [artisan command / rake task]

const CONNECTIONS = __CONNECTIONS__;
const QSTASH_TOKEN = __QSTASH_TOKEN__;
const DELIVERY_USAGE_URL = __DELIVERY_USAGE_URL__;

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
  }
}

const CLIENT_CERT = __CLIENT_CERT__;
const BROWSER = __BROWSER__;
App.outboundByHost = Object.fromEntries([
  ...CONNECTIONS.map((c) => [c.host, (request, env) => connectionFetch(c, request, env)]),
  ...(BROWSER ? [[__BROWSER_HOST__, (request, env) => browserFetch(request, env)]] : []),
]);
App.outbound = async (request, env) => {
  if (!CLIENT_CERT) return fetch(request);
  const presented = await env[CLIENT_CERT].fetch(request);
  return presented.status === 520 ? fetch(request) : presented;
};

async function connectionFetch(c, request, env) {
  if (c.asleep) return new Response('This resource is asleep.', { status: 503 });
  const binding = env[c.name];
  const url = new URL(request.url);
  const path = decodeURIComponent(url.pathname.replace(/^\//, ''));
  const json = async () => request.headers.get('content-type')?.includes('json') ? request.json() : {};
  if (c.kind === 'durable_object') {
    return binding.get(binding.idFromName('store')).fetch(request);
  }
  if (c.kind === 'key_value') {
    if (request.method === 'GET' && path === '') {
      const listed = await binding.list({ limit: 100 });
      return Response.json({ keys: (listed.keys || []).map((key) => key.name) });
    }
    if (path === '') return new Response('Name a key.', { status: 400 });
    if (request.method === 'GET') {
      const value = await binding.get(path, 'text');
      return new Response(value, { status: value == null ? 404 : 200, headers: { 'content-type': 'text/plain; charset=utf-8' } });
    }
    if (request.method === 'PUT') {
      const ttl = Number(request.headers.get('x-dply-ttl') || 0);
      await binding.put(path, await request.arrayBuffer(), ttl >= 60 ? { expirationTtl: Math.floor(ttl) } : {});
      return new Response(null, { status: 204 });
    }
    if (request.method === 'DELETE') {
      await binding.delete(path);
      return new Response(null, { status: 204 });
    }
  }
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
  if (c.kind === 'http_delivery' && request.method === 'POST') {
    if (!QSTASH_TOKEN) return new Response('HTTP delivery is not ready.', { status: 503 });
    const parsed = await json();
    const target = String(parsed.url || '');
    if (!target.startsWith('https://')) return new Response('Name an https address.', { status: 400 });
    const payload = typeof parsed.body === 'string' ? parsed.body : JSON.stringify(parsed.body ?? {});
    const headers = { authorization: 'Bearer ' + QSTASH_TOKEN, 'content-type': 'application/json' };
    if (parsed.delay) headers['upstash-delay'] = String(parsed.delay);
    const published = await fetch('https://qstash.upstash.io/v2/publish/' + target, { method: 'POST', headers, body: payload });
    if (published.ok) {
      await fetch(DELIVERY_USAGE_URL, { method: 'POST', headers: { 'content-type': 'application/json', 'x-dply-queue-token': env.DPLY_QUEUE_TOKEN }, body: JSON.stringify({ messages: 1, bytes: payload.length }) }).catch(() => {});
    }
    return new Response(await published.text(), { status: published.status });
  }
  if (c.kind === 'ai' && request.method === 'POST') { const body = await json(); return Response.json(await binding.run(body.model, body.input)); }
  if (c.kind === 'vectors' && request.method === 'POST') { const body = await json(); return Response.json(await binding.query(body.vector, { topK: body.topK || 5 })); }
  if (c.kind === 'images' && request.method === 'POST') return Response.json(await binding.info(await request.arrayBuffer()));
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
const STICKY = __STICKY__;
const DEDICATED_JOBS = __DEDICATED_JOBS__;
const PAUSE_KEY = __PAUSE_KEY__;

function stickyId(request) {
  const match = (request.headers.get('cookie') ?? '').match(/(?:^|;\s*)dply_instance=(\d+)/);
  if (match) {
    const id = Number(match[1]);
    if (id >= 0 && id < INSTANCES) return String(id);
  }
  return null;
}

async function webTarget(env, request) {
  if (!STICKY) return { container: await getRandom(env.APP, INSTANCES), cookie: null };
  const existing = stickyId(request);
  const id = existing ?? String(Math.floor(Math.random() * INSTANCES));
  return { container: getContainer(env.APP, id), cookie: existing === null ? id : null };
}

async function jobsTarget(env) {
  if (!DEDICATED_JOBS) return { container: await getRandom(env.APP, INSTANCES), cookie: null };
  return { container: getContainer(env.APP, 'jobs'), cookie: null };
}

function withStickyCookie(response, id) {
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
function httpRequest(request) {
  const url = new URL(request.url);
  url.protocol = 'http:';
  const init = {
    method: request.method,
    headers: new Headers(request.headers),
    redirect: 'manual',
  };
  if (request.method !== 'GET' && request.method !== 'HEAD') init.body = request.body;
  return new Request(url, init);
}

async function proxy(env, request, target) {
  // The public URL stays HTTPS. The container only accepts HTTP on this hop.
  request = httpRequest(request);
  const container = target.container;
  try {
    await container.startAndWaitForPorts({
      ports: [__PORT__],
      cancellationOptions: { portReadyTimeoutMS: 45000 },
    });
  } catch {
    // fetch() below starts the container again.
  }
  let response = await container.fetch(request);
  for (let attempt = 0; attempt < 2 && response.status >= 500; attempt++) {
    const preview = await response.clone().text();
    if (!/not running|Failed to start container|Container crashed|suddenly disconnected/.test(preview)) {
      return revealAppErrors(env, response);
    }
    try {
      await container.startAndWaitForPorts({
        ports: [__PORT__],
        cancellationOptions: { portReadyTimeoutMS: 45000 },
      });
    } catch {
      // fetch() below starts the container again.
    }
    response = await container.fetch(request);
  }
  if (target.cookie !== null) response = withStickyCookie(response, target.cookie);
  return revealAppErrors(env, response);
}

function revealAppErrors(env, response) {
  const flag = String(env.APP_DEBUG ?? '').trim().toLowerCase();
  if (flag !== 'true' && flag !== '1' && flag !== '(true)') return response;
  const headers = new Headers(response.headers);
  headers.set('x-dply-app-debug', '1');
  return new Response(response.body, { status: response.status, statusText: response.statusText, headers });
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (url.pathname.startsWith('/_dply/')) {
      if (request.headers.get('x-dply-queue-token') !== env.DPLY_QUEUE_TOKEN) {
        return new Response('Forbidden', { status: 403 });
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
      if (asset.status !== 404) return asset;
    }

    if (!(await trafficOpen(env))) {
      return new Response('This app is paused. The workspace usage credit is used up.', { status: 503, headers: { 'content-type': 'text/plain; charset=utf-8', 'retry-after': '3600' } });
    }
    const headers = new Headers(request.headers);
    headers.set('x-forwarded-proto', url.protocol.replace(':', ''));
    headers.set('x-forwarded-host', url.host);
    return proxy(env, new Request(request, { headers }), await webTarget(env, request));
  },

  // Cron Triggers: ask the app to run each handler for this schedule.
  async scheduled(controller, env, ctx) {
    if (!(await trafficOpen(env))) return;
    for (const handler of CRON_HANDLERS[controller.cron] ?? [null]) {
      ctx.waitUntil((async () => proxy(env, new Request('http://app' + __SCHEDULE_PATH__, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'x-dply-queue-token': env.DPLY_QUEUE_TOKEN },
        body: JSON.stringify({ cron: controller.cron, handler }),
      }), await jobsTarget(env)))());
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
     * Cron Triggers for the site: Crons tab / dply.yaml entries (handler =
     * artisan command or rake task) plus `schedule:run` every minute when the
     * scheduler is on. Cloudflare allows 5 schedules per Worker.
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
        if (EdgeContainerSettings::for($site)['scheduler']) {
            $crons['* * * * *'][] = 'schedule:run';
        }
        foreach (EdgeEffectiveCrons::for($site, $deployment) as $cron) {
            $crons[$cron['schedule']][] = $cron['handler'];
        }

        return array_slice($crons, 0, 5, true);
    }

    /**
     * Queue bindings (dashboard + repo) as binding name => queue name.
     *
     * @return array<string, string>
     */
    private function queueBindings(Site $site, EdgeDeployment $deployment): array
    {
        $out = [];
        foreach (EdgeEffectiveBindings::for($site, $deployment) as $binding) {
            if ($binding['kind'] === 'queue' && $binding['value'] !== '') {
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

    /** @param callable(string): void $log */
    private function ensureDeployerImage(callable $log): void
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
