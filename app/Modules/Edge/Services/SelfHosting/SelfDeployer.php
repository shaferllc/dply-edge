<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\SelfHosting;

use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\Containers\EdgeContainerDockerfile;
use App\Modules\Edge\Services\Containers\EdgeContainerRollout;
use App\Modules\Edge\Services\Containers\EdgePhpBaseImage;
use App\Modules\Edge\Services\EdgeBuildRunner;
use App\Modules\Edge\Support\EdgeContainerSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * The steps of dply:self:deploy: check out a commit, build dply's image with
 * the same Dockerfile generator customers get, run migrations in that image,
 * roll it out with the same deployer container (wrangler) and Worker project
 * a dashboard deploy writes, then health-check it.
 *
 * With dry-run on, every step and command is printed and nothing runs.
 */
class SelfDeployer
{
    public bool $dryRun = false;

    /** Set once wrangler starts: from here on production may be changed. */
    public bool $rolledOut = false;

    /** @var callable(string): void */
    private $log;

    public function __construct(private EdgeContainerDeployer $deployer)
    {
        $this->log = static function (string $line): void {};
    }

    /** @param callable(string): void $log */
    public function logTo(callable $log): static
    {
        $this->log = $log;

        return $this;
    }

    public function dbReachable(): bool
    {
        try {
            DB::connection()->select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The self site: the real row when the database answers, else an
     * unsaved stand-in from the R2 snapshot (id + edge meta), which is all
     * the image, Worker and health steps read.
     *
     * @param  array<string, mixed>  $worker  state.worker
     */
    public function site(string $siteId, bool $dbReachable, array $worker): Site
    {
        if ($dbReachable) {
            $site = Site::query()->find($siteId);
            if ($site !== null) {
                return $site;
            }
            throw new RuntimeException("Site {$siteId} is not in this database. Run dply:self:register against it first.");
        }

        $site = (new Site)->forceFill([
            'id' => $siteId,
            'meta' => ['edge' => is_array($worker['site_edge_meta'] ?? null) ? $worker['site_edge_meta'] : []],
        ]);
        $site->setRelation('organization', null);

        return $site;
    }

    public function resolveSha(string $repo, string $ref): string
    {
        $this->say("Resolve {$ref} to a commit", ['git', '-C', $repo, 'rev-parse', '--verify', $ref.'^{commit}']);
        if ($this->dryRun) {
            return '';
        }
        $result = Process::run(['git', '-C', $repo, 'rev-parse', '--verify', $ref.'^{commit}']);
        $sha = strtolower(trim($result->output()));
        if (! $result->successful() || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new RuntimeException("Could not resolve {$ref} in {$repo}. Run git fetch first.");
        }

        return $sha;
    }

    /**
     * Build, migrate (optional), and roll out one commit. Throws when any
     * step fails; the caller decides whether to roll back.
     *
     * @param  array<string, string>  $appEnv  the app's production env (never printed)
     * @param  array<string, mixed>  $worker  R2 snapshot, used when the database is down
     * @return array<string, mixed> the new snapshot (Worker files + site meta)
     */
    public function release(Site $site, bool $dbReachable, string $repo, string $sha, array $appEnv, bool $migrate, array $worker): array
    {
        $this->rolledOut = false;
        $label = $sha !== '' ? substr($sha, 0, 12) : '<sha>';
        $workRoot = rtrim(EdgeBuildRunner::buildRoot(), '/').'/dply-self-'.$label.'-'.now()->format('YmdHis');
        $checkout = $workRoot.'/src';
        $project = $workRoot.'/container-worker';

        // dply's own code is trusted: build on the machine's default builder,
        // not the customer sandbox (its iptables network only exists on the
        // build hosts). Only this process's config changes.
        config(['edge.build.containers.builder' => '', 'edge.build.sandbox.network' => '']);

        try {
            $this->say("Check out {$label} into {$checkout}", ['git', 'clone', '--quiet', '--no-checkout', $repo, $checkout]);
            $this->say('', ['git', '-C', $checkout, 'checkout', '--quiet', $sha !== '' ? $sha : '<sha>']);
            if (! $this->dryRun) {
                File::ensureDirectoryExists($workRoot);
                $this->mustRun(['git', 'clone', '--quiet', '--no-checkout', $repo, $checkout]);
                $this->mustRun(['git', '-C', $checkout, 'checkout', '--quiet', $sha]);
            }

            $this->say('Generate the container image definition (EdgeContainerDockerfile::prepare, same as customer apps)');
            $image = ['path' => $checkout.'/Dockerfile.dply', 'port' => 8080, 'server' => ''];
            if (! $this->dryRun) {
                try {
                    EdgePhpBaseImage::ensure($checkout, $this->log);
                } catch (Throwable $e) {
                    ($this->log)('Shared PHP image check skipped: '.$e->getMessage()."\n");
                }
                $image = EdgeContainerDockerfile::prepare($checkout, (bool) ($worker['inject_laravel'] ?? EdgeContainerDeployer::needsLaravelPackage($site, $checkout)));
                File::put($image['path'], EdgeContainerDeployer::scopeCacheMounts((string) file_get_contents($image['path']), EdgeContainerDeployer::cacheScope($site)));
            }

            $this->say($dbReachable
                ? 'Write the Worker project from the self site (EdgeContainerDeployer::writeProject)'
                : 'Database unreachable: write the Worker project from the R2 snapshot');
            $queues = [];
            if (! $this->dryRun) {
                $queues = $dbReachable
                    ? $this->deployer->writeProject($project, $site, null, $checkout, $image)['queues']
                    : $this->projectFromSnapshot($project, $site, $checkout, $image, $worker);
            }

            $secrets = $this->secrets($site, $dbReachable, $appEnv, $queues);
            $this->say('Write secrets.json: '.count($secrets).' keys (values not shown)');

            $tag = 'dply-self:'.$label;
            if ($migrate) {
                $this->say('Build the image locally for the migration (linux/amd64)', ['docker', 'buildx', 'build', '--platform', 'linux/amd64', '--load', '-t', $tag, '-f', $image['path'], $checkout]);
                $this->say('Run migrations once, in the new image, before any traffic reaches it', self::migrateCommand($tag, array_keys($secrets)));
                if (! $this->dryRun) {
                    $this->mustRun(['docker', 'buildx', 'build', '--platform', 'linux/amd64', '--load', '-t', $tag, '-f', $image['path'], $checkout], 1800);
                    $this->mustRun(self::migrateCommand($tag, array_keys($secrets)), 900, $secrets);
                }
            } else {
                $this->say('Migrations: skipped (rollbacks never migrate; migrations must stay backward-compatible)');
            }

            $namespace = (string) config('edge.cloudflare.dispatch_namespace_name');
            $mode = $this->rolloutMode($site);
            $command = EdgeContainerDeployer::deployerCommand('dply-self-deploy-'.$label, $workRoot, $project, $namespace, $mode);
            $this->say('Build, push and roll out with wrangler in the deployer image (account '.config('edge.cloudflare.account_id').", namespace {$namespace}, script ".EdgeContainerDeployer::scriptName($site).", rollout {$mode})", $command);
            if ($this->dryRun) {
                return [];
            }
            $this->deployer->ensureDeployerImage($this->log);
            $this->rolledOut = true;
            File::put($project.'/secrets.json', json_encode($secrets, JSON_THROW_ON_ERROR));
            try {
                $result = Process::timeout(1800)->run($command, function (string $type, string $output): void {
                    ($this->log)($output);
                });
            } finally {
                File::delete($project.'/secrets.json');
            }
            if (! $result->successful()) {
                throw new RuntimeException('wrangler deploy failed: '.EdgeContainerDeployer::failureReason($result->errorOutput(), $result->output()));
            }

            return [
                'files' => array_filter([
                    'wrangler.jsonc' => @file_get_contents($project.'/wrangler.jsonc') ?: null,
                    'src/index.js' => @file_get_contents($project.'/src/index.js') ?: null,
                    'package.json' => @file_get_contents($project.'/package.json') ?: null,
                ]),
                'site_edge_meta' => $site->edgeMeta(),
                'inject_laravel' => $worker['inject_laravel'] ?? ($dbReachable ? EdgeContainerDeployer::needsLaravelPackage($site, $checkout) : false),
            ];
        } finally {
            if (! $this->dryRun) {
                File::deleteDirectory($workRoot);
            }
        }
    }

    /** @return array{ok: bool, settled: bool, reason: ?string} */
    public function awaitRollout(Site $site): array
    {
        $this->say('Wait for Cloudflare to report the container rollout settled');
        if ($this->dryRun) {
            return ['ok' => true, 'settled' => true, 'reason' => null];
        }

        return app(EdgeContainerRollout::class)->await($site, $this->log, 600);
    }

    /**
     * The new container answers on the site's platform hostname straight
     * away; edge.dply.io is checked too once it is attached to the site.
     * Every URL must answer 200.
     *
     * @return list<string>
     */
    public function healthUrls(Site $site): array
    {
        $path = '/'.ltrim((string) config('edge.self.health_path', '/up'), '/');
        $urls = [];
        $live = $site->edgeLiveUrl();
        if ($live !== null) {
            $urls[] = rtrim($live, '/').$path;
        }
        // Only once it is this site's custom domain: before cutover it is the
        // old control plane (possibly in maintenance mode, 503).
        $host = strtolower(trim((string) config('edge.self.hostname')));
        $attached = $site->edgeMeta()['routing']['custom_domains'] ?? [];
        if ($host !== '' && is_array($attached) && array_key_exists($host, $attached)) {
            $urls[] = 'https://'.$host.$path;
        }

        return array_values(array_unique($urls));
    }

    /** Null when healthy, else why not. Retries for about two minutes. */
    public function healthFailure(Site $site, int $attempts = 12, int $sleepSeconds = 10): ?string
    {
        $failure = null;
        foreach ($this->healthUrls($site) as $url) {
            $this->say("Health check: GET {$url} must answer 200");
            if ($this->dryRun) {
                continue;
            }
            $failure = "{$url} never answered";
            for ($try = 1; $try <= $attempts; $try++) {
                try {
                    $response = Http::timeout(20)->withoutRedirecting()->get($url);
                    if ($response->status() === 200) {
                        ($this->log)("{$url} answered 200.\n");
                        $failure = null;
                        break;
                    }
                    $failure = EdgeContainerDeployer::unhealthyReason($url, $response->status(), $response->body()) ?? "{$url} answered HTTP {$response->status()}";
                } catch (Throwable $e) {
                    $failure = "{$url} did not answer: ".$e->getMessage();
                }
                if ($try < $attempts && $sleepSeconds > 0) {
                    sleep($sleepSeconds);
                }
            }
            if ($failure !== null) {
                return $failure;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function migrateCommand(string $tag, array $keys): array
    {
        // `-e KEY` with no value: docker reads it from this process's env,
        // so no secret is ever on a command line.
        $env = [];
        foreach ($keys as $key) {
            array_push($env, '-e', $key);
        }

        return ['docker', 'run', '--rm', '--platform', 'linux/amd64', ...$env, $tag, 'php', 'artisan', 'migrate', '--force'];
    }

    /**
     * A command as it is safe to print: `-e KEY=value` keeps only the key.
     *
     * @param  list<string>  $command
     */
    public static function printable(array $command): string
    {
        return implode(' ', array_map(static function (string $part): string {
            $part = (string) preg_replace('/^([A-Z][A-Z0-9_]*)=.*$/s', '$1=***', $part);

            return preg_match('/^[\w@%+=:,.\/<>^{}-]+$/', $part) === 1 ? $part : escapeshellarg($part);
        }, $command));
    }

    /**
     * @param  array<string, string>  $appEnv
     * @param  array<string, string>  $queues
     * @return array<string, string>
     */
    private function secrets(Site $site, bool $dbReachable, array $appEnv, array $queues): array
    {
        $control = [
            // The self app is always the container role, whatever the file says.
            'DPLY_RUNTIME' => 'container',
            // Migrations run once, before rollout, never on boot.
            'DPLY_MIGRATE_ON_BOOT' => '0',
        ];
        if ($dbReachable && ! $this->dryRun) {
            return array_merge($this->deployer->secrets($site, $appEnv, $queues, false), $control);
        }

        // wrangler keeps secrets left out of the file, so the resource env the
        // last dashboard deploy set is still there.
        return array_merge($appEnv, [
            'DPLY_QUEUE_TOKEN' => EdgeContainerDeployer::queueToken($site),
            'DPLY_APP_URL' => (string) ($site->edgeLiveUrl() ?? ''),
        ], $control);
    }

    /**
     * @param  array{path: string, port: int, server?: string}  $image
     * @param  array<string, mixed>  $worker
     * @return array<string, string>
     */
    private function projectFromSnapshot(string $project, Site $site, string $checkout, array $image, array $worker): array
    {
        $files = is_array($worker['files'] ?? null) ? $worker['files'] : [];
        if (! isset($files['wrangler.jsonc'], $files['src/index.js'], $files['package.json'])) {
            throw new RuntimeException('The database is unreachable and R2 has no Worker snapshot yet. Deploy once with the database up (dply:self:deploy) so one is saved.');
        }
        File::ensureDirectoryExists($project.'/src');
        foreach ($files as $name => $contents) {
            File::put($project.'/'.$name, (string) $contents);
        }
        $config = json_decode((string) $files['wrangler.jsonc'], true, flags: JSON_THROW_ON_ERROR);
        $config['containers'][0]['image'] = $image['path'];
        unset($config['assets']);
        File::put($project.'/wrangler.jsonc', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->deployer->attachStaticAssets($project, $checkout, $site);

        $queues = [];
        foreach ((array) ($config['queues']['producers'] ?? []) as $producer) {
            $queues[(string) $producer['binding']] = (string) $producer['queue'];
        }

        return $queues;
    }

    private function rolloutMode(Site $site): string
    {
        try {
            return EdgeContainerSettings::for($site)['rollout_mode'];
        } catch (Throwable) {
            return 'gradual';
        }
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function mustRun(array $command, int $timeout = 600, array $env = []): void
    {
        $result = Process::timeout($timeout)->env($env)->run($command, function (string $type, string $output): void {
            ($this->log)($output);
        });
        if (! $result->successful()) {
            throw new RuntimeException(self::printable($command).' failed: '.EdgeContainerDeployer::failureReason($result->errorOutput(), $result->output()));
        }
    }

    /** @param list<string> $command */
    private function say(string $step, array $command = []): void
    {
        if ($step !== '') {
            ($this->log)(($this->dryRun ? '[dry-run] ' : '').'→ '.$step."\n");
        }
        if ($command !== []) {
            ($this->log)('    $ '.self::printable($command)."\n");
        }
    }
}
