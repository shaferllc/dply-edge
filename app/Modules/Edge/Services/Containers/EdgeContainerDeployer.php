<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Containers;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Billing\Services\StarterTrafficGate;
use App\Modules\Edge\Services\EdgeDeliveryContextResolver;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeEffectiveBindings;
use App\Modules\Edge\Support\EdgeEffectiveCrons;
use App\Modules\Edge\Support\EdgeLogCopy;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
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
        $image = EdgeContainerDockerfile::prepare($checkout);
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
            "Container settings: %s, %d instance(s) (+1 deploy slot), sleep %s\n",
            $settings['instance_type'],
            $settings['max_instances'],
            $settings['sleep_after'],
        ));

        $withDefaults = EdgeContainerEnvDefaults::ensure($site, $checkout, $env);
        $log(EdgeContainerEnvDefaults::describe($env, $withDefaults));
        $env = $withDefaults;

        $project = $workRoot.'/container-worker';
        $queues = $this->queueBindings($site, $deployment);
        $this->scaffold($project, $site, $image['path'], $image['port'], $queues, self::cronHandlers($site, $deployment), $this->billingKvNamespaceId($site));

        File::put($project.'/secrets.json', json_encode(array_merge($env, [
            'DPLY_QUEUE_TOKEN' => self::queueToken($site),
            'DPLY_APP_URL' => (string) ($site->edgeLiveUrl() ?? ''),
            'DPLY_MIGRATE_ON_BOOT' => EdgeContainerSettings::for($site)['migrate_on_boot'] ? '1' : '0',
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
            'sh', '-c', 'npm install --silent --no-audit --no-fund && wrangler deploy --dispatch-namespace "$0" --secrets-file secrets.json --containers-rollout gradual',
            $namespace,
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
                'instance_type' => $settings['instance_type'],
                'max_instances' => EdgeContainerSettings::wranglerMaxInstances($settings['max_instances']),
                'constraints' => $settings['jurisdiction'] !== '' ? ['jurisdiction' => $settings['jurisdiction']] : null,
            ])],
            'durable_objects' => ['bindings' => [['name' => 'APP', 'class_name' => 'App']]],
            'migrations' => [['tag' => 'v1', 'new_sqlite_classes' => ['App']]],
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

        File::put($dir.'/wrangler.jsonc', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        File::put($dir.'/package.json', json_encode([
            'name' => self::scriptName($site),
            'private' => true,
            'type' => 'module',
            'dependencies' => ['@cloudflare/containers' => '^0'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::put($dir.'/src/index.js', $this->workerSource($port, array_flip($queues), $settings, $crons, (string) $site->id));
    }

    /**
     * @param  array<string, string>  $queueBindings  queue name => binding name
     * @param  array{instance_type: string, max_instances: int, sleep_after: string, migrate_on_boot: bool, jurisdiction: string, scheduler: bool}  $settings
     * @param  array<string, list<?string>>  $crons
     */
    private function workerSource(int $port, array $queueBindings, array $settings, array $crons, string $siteId): string
    {
        $replace = [
            '__PORT__' => (string) $port,
            '__SLEEP__' => json_encode($settings['sleep_after']),
            '__INSTANCES__' => (string) $settings['max_instances'],
            '__FPM_CHILDREN__' => (string) EdgeContainerSettings::phpFpmPool($settings['instance_type'])['max_children'],
            '__FPM_LIMIT__' => json_encode(EdgeContainerSettings::phpFpmPool($settings['instance_type'])['memory_limit']),
            '__QUEUE_PATH__' => json_encode(self::QUEUE_PATH, JSON_UNESCAPED_SLASHES),
            '__QUEUE_SEND_PATH__' => json_encode(self::QUEUE_SEND_PATH, JSON_UNESCAPED_SLASHES),
            '__QUEUE_BINDINGS__' => json_encode((object) $queueBindings, JSON_UNESCAPED_SLASHES),
            '__SCHEDULE_PATH__' => json_encode(self::SCHEDULE_PATH, JSON_UNESCAPED_SLASHES),
            '__CRON_HANDLERS__' => json_encode((object) $crons, JSON_UNESCAPED_SLASHES),
            '__PAUSE_KEY__' => json_encode(StarterTrafficGate::KEY_PREFIX.$siteId),
        ];

        return strtr(<<<'JS'
// Generated by dply (EdgeContainerDeployer). Edits are overwritten on deploy.
import { Container, getRandom } from '@cloudflare/containers';

const QUEUE_BINDINGS = __QUEUE_BINDINGS__; // queue name -> binding name
const CRON_HANDLERS = __CRON_HANDLERS__; // schedule -> [artisan command / rake task]

export class App extends Container {
  defaultPort = __PORT__;
  sleepAfter = __SLEEP__;

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

const app = (env) => getRandom(env.APP, __INSTANCES__);
const PAUSE_KEY = __PAUSE_KEY__;

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
async function proxy(env, request) {
  const container = await app(env);
  let response = await container.fetch(request);
  for (let attempt = 0; attempt < 2 && response.status >= 500; attempt++) {
    const preview = await response.clone().text();
    if (!/not running|Failed to start container|Container crashed|suddenly disconnected/.test(preview)) {
      return response;
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
  return response;
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

    if (!(await trafficOpen(env))) {
      return new Response('This app is paused. The workspace usage credit is used up.', { status: 503, headers: { 'content-type': 'text/plain; charset=utf-8', 'retry-after': '3600' } });
    }
    const headers = new Headers(request.headers);
    headers.set('x-forwarded-proto', url.protocol.replace(':', ''));
    headers.set('x-forwarded-host', url.host);
    return proxy(env, new Request(request, { headers }));
  },

  // Cron Triggers: ask the app to run each handler for this schedule.
  async scheduled(controller, env, ctx) {
    if (!(await trafficOpen(env))) return;
    for (const handler of CRON_HANDLERS[controller.cron] ?? [null]) {
      ctx.waitUntil((async () => proxy(env, new Request('http://app' + __SCHEDULE_PATH__, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'x-dply-queue-token': env.DPLY_QUEUE_TOKEN },
        body: JSON.stringify({ cron: controller.cron, handler }),
      })))());
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
    }));
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
