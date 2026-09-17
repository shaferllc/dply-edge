<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Containers;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeEffectiveBindings;
use App\Modules\Edge\Support\EdgeEffectiveCrons;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

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
    public const QUEUE_PATH = '/_dply/queue';

    public const QUEUE_SEND_PATH = '/_dply/queue/send';

    public const SCHEDULE_PATH = '/_dply/schedule';

    public static function scriptName(Site $site): string
    {
        return 'dply-ctr-'.strtolower((string) $site->id);
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
        $log(sprintf("Container image: %s (%s, port %d)\n", $image['generated'] ? 'generated Dockerfile.dply' : 'repo Dockerfile', $image['stack'], $image['port']));

        $project = $workRoot.'/container-worker';
        $queues = $this->queueBindings($site, $deployment);
        $this->scaffold($project, $site, $image['path'], $image['port'], $queues, self::cronHandlers($site, $deployment));

        File::put($project.'/secrets.json', json_encode(array_merge($env, [
            'DPLY_QUEUE_TOKEN' => self::queueToken($site),
            'DPLY_APP_URL' => (string) ($site->edgeLiveUrl() ?? ''),
            'DPLY_MIGRATE_ON_BOOT' => EdgeContainerSettings::for($site)['migrate_on_boot'] ? '1' : '0',
        ]), JSON_THROW_ON_ERROR));

        $this->ensureDeployerImage($log);

        $namespace = (string) config('edge.cloudflare.dispatch_namespace_name');
        $log("[dply:step] deploy\nwrangler deploy --dispatch-namespace {$namespace}\n");

        // Same absolute path inside the deployer so the Dockerfile path in
        // wrangler.jsonc resolves; the host socket does the actual build.
        $result = Process::timeout($timeoutSeconds ?? 1800)->run([
            'docker', 'run', '--rm',
            '-v', '/var/run/docker.sock:/var/run/docker.sock',
            '-v', $workRoot.':'.$workRoot,
            '-w', $project,
            '-e', 'CLOUDFLARE_API_TOKEN='.config('edge.cloudflare.api_token'),
            '-e', 'CLOUDFLARE_ACCOUNT_ID='.config('edge.cloudflare.account_id'),
            (string) config('edge.build.containers.deployer_image'),
            'sh', '-c', 'npm install --silent --no-audit --no-fund && wrangler deploy --dispatch-namespace "$0" --secrets-file secrets.json --containers-rollout immediate',
            $namespace,
        ], fn (string $type, string $output) => $log($output));

        File::delete($project.'/secrets.json');

        if (! $result->successful()) {
            throw new RuntimeException('Container deploy failed: '.trim(substr($result->errorOutput() ?: $result->output(), -800)));
        }

        return ['script_name' => self::scriptName($site), 'stack' => $image['stack'], 'port' => $image['port'], 'queues' => array_keys($queues)];
    }

    /**
     * Write the Worker project wrangler deploys.
     *
     * @param  array<string, string>  $queues  binding name => queue name
     * @param  array<string, list<?string>>  $crons  schedule => handlers (artisan command / rake task)
     */
    public function scaffold(string $dir, Site $site, string $dockerfile, int $port, array $queues, array $crons = []): void
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
                'max_instances' => $settings['max_instances'],
                'constraints' => $settings['jurisdiction'] !== '' ? ['jurisdiction' => $settings['jurisdiction']] : null,
            ])],
            'durable_objects' => ['bindings' => [['name' => 'APP', 'class_name' => 'App']]],
            'migrations' => [['tag' => 'v1', 'new_sqlite_classes' => ['App']]],
        ];
        if ($queues !== []) {
            $config['queues'] = [
                'producers' => array_map(static fn (string $name, string $queue): array => ['binding' => $name, 'queue' => $queue], array_keys($queues), $queues),
                'consumers' => array_map(static fn (string $queue): array => ['queue' => $queue, 'max_batch_size' => 10, 'max_retries' => 5], array_values($queues)),
            ];
        }

        if ($crons !== []) {
            $config['triggers'] = ['crons' => array_keys($crons)];
        }

        File::put($dir.'/wrangler.jsonc', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        File::put($dir.'/package.json', json_encode([
            'name' => self::scriptName($site),
            'private' => true,
            'type' => 'module',
            'dependencies' => ['@cloudflare/containers' => '^0'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::put($dir.'/src/index.js', $this->workerSource($port, array_flip($queues), $settings, $crons));
    }

    /**
     * @param  array<string, string>  $queueBindings  queue name => binding name
     * @param  array{instance_type: string, max_instances: int, sleep_after: string, migrate_on_boot: bool, jurisdiction: string, scheduler: bool}  $settings
     * @param  array<string, list<?string>>  $crons
     */
    private function workerSource(int $port, array $queueBindings, array $settings, array $crons): string
    {
        $replace = [
            '__PORT__' => (string) $port,
            '__SLEEP__' => json_encode($settings['sleep_after']),
            '__INSTANCES__' => (string) $settings['max_instances'],
            '__QUEUE_PATH__' => json_encode(self::QUEUE_PATH, JSON_UNESCAPED_SLASHES),
            '__QUEUE_SEND_PATH__' => json_encode(self::QUEUE_SEND_PATH, JSON_UNESCAPED_SLASHES),
            '__QUEUE_BINDINGS__' => json_encode((object) $queueBindings, JSON_UNESCAPED_SLASHES),
            '__SCHEDULE_PATH__' => json_encode(self::SCHEDULE_PATH, JSON_UNESCAPED_SLASHES),
            '__CRON_HANDLERS__' => json_encode((object) $crons, JSON_UNESCAPED_SLASHES),
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
    this.envVars = Object.fromEntries(Object.entries(env).filter(([, v]) => typeof v === 'string'));
  }
}

const app = (env) => getRandom(env.APP, __INSTANCES__);

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

    return (await app(env)).fetch(request);
  },

  // Cron Triggers: ask the app to run each handler for this schedule.
  async scheduled(controller, env, ctx) {
    for (const handler of CRON_HANDLERS[controller.cron] ?? [null]) {
      ctx.waitUntil((async () => (await app(env)).fetch(new Request('http://app' + __SCHEDULE_PATH__, {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'x-dply-queue-token': env.DPLY_QUEUE_TOKEN },
        body: JSON.stringify({ cron: controller.cron, handler }),
      })))());
    }
  },

  // Push each batch into the app; it answers { failed: [message ids] }.
  async queue(batch, env) {
    const response = await (await app(env)).fetch(new Request('http://app' + __QUEUE_PATH__, {
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

    /** @param callable(string): void $log */
    private function ensureDeployerImage(callable $log): void
    {
        $image = (string) config('edge.build.containers.deployer_image');
        if (Process::run(['docker', 'image', 'inspect', $image])->successful()) {
            return;
        }

        $log("Building deployer image {$image}…\n");
        $build = Process::timeout(900)->run(['docker', 'build', '-t', $image, base_path('docker/edge-container-deployer')]);
        if (! $build->successful()) {
            throw new RuntimeException('Could not build the container deployer image: '.trim(substr($build->errorOutput(), -400)));
        }
    }
}
