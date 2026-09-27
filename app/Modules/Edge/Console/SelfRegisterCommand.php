<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Actions\Organizations\EnsureUserHasWorkspaceOrganization;
use App\Enums\SiteType;
use App\Models\EdgeSiteEnvVar;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Console\Concerns\RunsWithEnvFile;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Edge\Services\EdgeCustomDomainProvisioner;
use App\Modules\Edge\Services\SelfHosting\SelfDeployState;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeTestingDomains;
use App\Modules\Edge\Support\EdgeValkey;
use App\Support\DplyRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Dogfood: dply as a normal container Site in dply, so day-to-day deploys
 * are push-to-deploy from its own dashboard. Idempotent — run it again to
 * converge settings. Does NOT deploy (CreateEdgeSite would queue a build);
 * the first deploy is dply:self:deploy. See docs/self-hosting-runbook.md.
 */
class SelfRegisterCommand extends Command
{
    use RunsWithEnvFile;

    /** @var array{site_id: string, deploys: list<array<string, string>>, worker: array<string, mixed>, bootstrap: array<string, mixed>} */
    private array $saved = ['site_id' => '', 'deploys' => [], 'worker' => [], 'bootstrap' => []];

    protected $signature = 'dply:self:register
        {--env-file= : Production .env to run against (default: this process\'s env)}
        {--organization= : Owning organization id (default: DPLY_SELF_ORGANIZATION_ID)}
        {--owner= : Owner email (default: DPLY_SELF_OWNER_EMAIL, else the org\'s first member)}
        {--owner-name=dply admin : With --bootstrap: the owner\'s name if the user is created}
        {--bootstrap : Fresh database: create the owner and the organization if missing, and give the site the id dply:self:bootstrap chose}
        {--skip-resources : Do not start the dply database or Valkey}
        {--database-size=0.5 : dply Postgres size (compute units)}
        {--valkey-class=flex_1g : dply Valkey class}
        {--attach-domain : Cut over: attach edge.dply.io to this site (a Cloudflare write; do it after the first healthy deploy)}
        {--dry-run : Show what would change}';

    protected $description = 'Create or update dply itself as a container Site in dply (dogfood)';

    public function handle(SelfDeployState $state): int
    {
        $envFile = trim((string) $this->option('env-file'));
        if ($envFile !== '' && ! $this->isEnvFileChild()) {
            return $this->reexecWithEnvFile($envFile, ['APP_KEY', 'DPLY_EDGE_R2_BUCKET', 'DPLY_EDGE_CF_ACCOUNT_ID', 'DPLY_EDGE_CF_API_TOKEN']);
        }

        $dryRun = (bool) $this->option('dry-run');
        try {
            $this->saved = $state->load();
        } catch (Throwable $e) {
            $this->warn('R2 deploy state not readable: '.$e->getMessage());
        }
        $email = (string) ($this->option('owner') ?: config('edge.self.owner_email'));
        $organization = Organization::query()->find((string) ($this->option('organization') ?: config('edge.self.organization_id')));
        if ($organization === null && $this->option('bootstrap') && $email !== '' && ! $dryRun) {
            $organization = $this->bootstrapOwner($email);
        }
        if ($organization === null) {
            $this->error($this->option('bootstrap')
                ? 'Pass --owner=<email> to create the owner and the dply organization.'
                : 'Organization not found. Pass --organization=<id> or set DPLY_SELF_ORGANIZATION_ID (fresh database: --bootstrap --owner=<email>).');

            return $dryRun && $this->option('bootstrap') ? self::SUCCESS : self::FAILURE;
        }
        $owner = $email !== '' ? User::query()->where('email', $email)->first() : $organization->users()->first();
        if (! $owner instanceof User) {
            $this->error('Owner not found. Pass --owner=<email>.');

            return self::FAILURE;
        }

        $site = $this->find($organization);
        $this->line($site === null ? 'Self site: none yet, will create it.' : "Self site: {$site->id} ({$site->name}), will update it.");

        // dply must never be trial-clamped (EdgeTrialLimits) or paused by
        // dply:billing:enforce for want of a card.
        if ($organization->comped_until === null || $organization->comped_until->isBefore(now()->addYears(10))) {
            $this->line("Comp organization {$organization->id} (it must never be paused for billing).");
            if (! $dryRun) {
                $organization->forceFill(['comped_until' => now()->addYears(100)])->save();
            }
        }

        if ($dryRun) {
            $this->line('Would set: runtime container, repo '.config('edge.self.repo').'@'.config('edge.self.branch').', deploy on push, always-on web (min 1), queue workers on '.DplyRuntime::workerQueueList(DplyRuntime::MODE_CONTAINER).' with the scheduler in worker-0, migrate on boot off.');
            if (! $this->option('skip-resources')) {
                $this->line('Would start (if missing): dply Postgres '.$this->option('database-size').' CU (stays on), dply Valkey '.$this->option('valkey-class').'.');
            }

            return self::SUCCESS;
        }

        $site = $this->upsert($organization, $owner, $site);
        $this->putEnv($site, 'DPLY_RUNTIME', DplyRuntime::MODE_CONTAINER);
        $this->putEnv($site, 'APP_URL', 'https://'.config('edge.self.hostname'));
        $this->info("Self site {$site->id} is registered.");

        if (! $this->option('skip-resources')) {
            $this->ensureResources($site);
        }

        try {
            $saved = $this->saved;
            $saved['site_id'] = (string) $site->id;
            $saved['worker'] = array_merge($saved['worker'], ['site_edge_meta' => $site->fresh()?->edgeMeta() ?? $site->edgeMeta()]);
            $state->save($saved);
            $this->line('Saved the site id to the R2 deploy state.');
        } catch (Throwable $e) {
            $this->warn('Could not save the site id to R2 ('.$e->getMessage()."). Set DPLY_SELF_SITE_ID={$site->id} for dply:self:deploy.");
        }

        if ($this->option('attach-domain')) {
            $host = (string) config('edge.self.hostname');
            $result = app(EdgeCustomDomainProvisioner::class)->provision($site, $host);
            $this->info("Attached {$host}: ".json_encode($result));
        } else {
            $this->line('edge.dply.io is not attached yet. After the first healthy deploy: php artisan dply:self:register --attach-domain');
        }

        return self::SUCCESS;
    }

    /** The self site: by id (config, then R2 state), else the flagged one in this org. */
    private function find(Organization $organization): ?Site
    {
        $id = (string) config('edge.self.site_id') ?: $this->saved['site_id'];
        if ($id !== '' && ($site = Site::query()->find($id)) !== null) {
            return $site;
        }

        return Site::query()->where('organization_id', $organization->id)->get()
            ->first(fn (Site $site): bool => (bool) ($site->edgeMeta()['self_hosted'] ?? false));
    }

    private function upsert(Organization $organization, User $owner, ?Site $site): Site
    {
        if ($site === null) {
            $server = Server::query()->create([
                'user_id' => $owner->id,
                'organization_id' => $organization->id,
                'name' => 'edge-dply',
                'status' => Server::STATUS_READY,
                'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
            ]);
            $hostname = 'dply-'.strtolower(Str::random(6)).'.'.EdgeTestingDomains::defaultApex();
            // The id dply:self:bootstrap chose: its database and Valkey are
            // named after it, and the Worker script is dply-ctr-<id>.
            $id = (string) config('edge.self.site_id') ?: $this->saved['site_id'];
            $site = (new Site)->forceFill(array_filter(['id' => $id !== '' ? $id : null]));
            $site->fill([
                'server_id' => $server->id,
                'user_id' => $owner->id,
                'organization_id' => $organization->id,
                'name' => (string) config('edge.self.name', 'dply'),
                'slug' => 'dply',
                'type' => SiteType::Static,
                'edge_backend' => (string) config('edge.default_backend', 'dply_edge'),
                'status' => Site::STATUS_EDGE_PROVISIONING,
                'webhook_secret' => Str::random(48),
                'meta' => ['runtime_profile' => 'edge_web', 'edge' => [
                    'routing' => ['spa_fallback' => false, 'headers' => [], 'hostname' => $hostname],
                    'live_url' => 'https://'.$hostname,
                ]],
            ])->save();
        }

        $edge = $site->edgeMeta();
        $container = is_array($edge['container'] ?? null) ? $edge['container'] : [];
        $site->mergeEdgeMeta([
            'self_hosted' => true,
            'runtime_mode' => 'container',
            'source' => array_merge((array) ($edge['source'] ?? []), [
                'repo' => (string) config('edge.self.repo'),
                'branch' => (string) config('edge.self.branch'),
                'deploy_on_push' => true,
            ]),
            'build' => array_merge((array) ($edge['build'] ?? []), ['framework' => 'laravel']),
            // Operator-tuned values (size, instance counts) survive a re-run;
            // the invariants below are forced.
            'container' => array_merge([
                'instance_type' => 'standard-1',
                'max_instances' => 3,
                'sleep_after' => '1h',
                'rollout_mode' => 'gradual',
            ], $container, [
                'min_instances' => max(1, (int) ($container['min_instances'] ?? 1)),
                'migrate_on_boot' => false,
                'scheduler' => true,
                'workers' => array_merge([
                    'instances' => 1,
                    'processes' => 4,
                    'connection' => 'redis',
                    'timeout' => 900,
                ], is_array($container['workers'] ?? null) ? $container['workers'] : [], [
                    'enabled' => true,
                    'paused' => false,
                    'queues' => DplyRuntime::workerQueueList(DplyRuntime::MODE_CONTAINER),
                ]),
            ]),
            'database' => is_array($edge['database'] ?? null) ? $edge['database'] : ['engine' => 'sql', 'name' => 'production'],
        ]);
        $site->save();

        return $site;
    }

    private function ensureResources(Site $site): void
    {
        $database = $site->edgeMeta()['database'] ?? [];
        $boot = $this->saved['bootstrap'];
        if ((string) ($database['remote_id'] ?? '') !== '' && ($database['engine'] ?? '') === 'postgres') {
            $this->line('dply Postgres: already attached.');
        } elseif (is_array($boot['database'] ?? null)) {
            $this->adoptDatabase($site, $boot['database']);
        } else {
            $error = EdgeAppDatabase::sync($site, (string) ($database['engine'] ?? 'sql'), 'postgres', 'awake', (string) $this->option('database-size'), -1);
            $error === null ? $this->info('dply Postgres: started.') : $this->error('dply Postgres: '.$error);
        }

        if (array_filter(EdgeContainerConnections::for($site), static fn (array $c): bool => $c['kind'] === 'redis') !== []) {
            $this->line('dply Valkey: already attached.');

            return;
        }
        if (is_array($boot['valkey'] ?? null) && trim((string) getenv('REDIS_URL')) !== '') {
            $this->attachValkey($site, (string) $boot['valkey']['target'], trim((string) getenv('REDIS_URL')));

            return;
        }
        try {
            $class = (string) $this->option('valkey-class');
            $valkey = EdgeValkey::provision($site, 'cache', $class, EdgeValkey::DEFAULT_SLEEP);
            $this->attachValkey($site, $valkey['target'], $valkey['url']);
        } catch (Throwable $e) {
            $this->error('dply Valkey: '.$e->getMessage());
        }
    }

    private function attachValkey(Site $site, string $target, string $url): void
    {
        $error = EdgeContainerConnections::attach($site, 'redis', 'CACHE', $target);
        if ($error !== null) {
            $this->error('dply Valkey: '.$error);

            return;
        }
        $parts = parse_url($url) ?: [];
        $this->putEnv($site, 'REDIS_URL', $url);
        $this->putEnv($site, 'REDIS_USERNAME', rawurldecode((string) ($parts['user'] ?? '')) ?: 'default');
        $this->putEnv($site, 'REDIS_PASSWORD', rawurldecode((string) ($parts['pass'] ?? '')));
        $this->info("dply Valkey: attached {$target}.");
    }

    /**
     * The database dply:self:bootstrap created before this database had any
     * tables: record it on the site like EdgeAppDatabase does, credentials
     * from this process's own connection (the same database).
     *
     * @param  array<string, mixed>  $record
     */
    private function adoptDatabase(Site $site, array $record): void
    {
        $db = (array) config('database.connections.'.config('database.default'));
        EdgeAppDatabase::storeCredentials($site, 'postgres', [
            'host' => (string) ($db['host'] ?? $record['host'] ?? ''),
            'port' => (string) ($db['port'] ?? '5432'),
            'database' => (string) ($db['database'] ?? 'app'),
            'username' => (string) ($db['username'] ?? 'app'),
            'password' => (string) ($db['password'] ?? ''),
        ]);
        $site->mergeEdgeMeta(['database' => [
            'engine' => 'postgres',
            'provider' => 'dply',
            'name' => 'production',
            'status' => 'ready',
            'remote_id' => (string) $record['remote_id'],
            'host' => (string) $record['host'],
            'region' => (string) $record['region'],
            'plan' => 'awake',
            'size' => (string) $record['size'],
            'suspend' => -1,
            'disk_gb' => (int) $record['disk_gb'],
            'storage_at' => now()->timestamp,
        ]]);
        $site->save();
        $this->info("dply Postgres: attached {$record['remote_id']}.");
    }

    /** Fresh database: the owner (verified; set a password with "Forgot password") and the dply organization. */
    private function bootstrapOwner(string $email): Organization
    {
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $user = (new User)->forceFill([
                'name' => (string) $this->option('owner-name'),
                'email' => $email,
                'password' => Hash::make(Str::random(64)),
                'email_verified_at' => now(),
            ]);
            $user->save();
            $this->info("Created the owner {$email}. Set a password with \"Forgot password\" on the login page.");
        }
        $organization = EnsureUserHasWorkspaceOrganization::run($user);
        $organization->forceFill(['name' => (string) config('edge.self.name', 'dply')])->save();
        $this->info("Organization {$organization->id} ({$organization->name}). Set DPLY_SELF_ORGANIZATION_ID={$organization->id} and add {$email} to PLATFORM_ADMIN_EMAILS.");

        return $organization;
    }

    private function putEnv(Site $site, string $key, string $value): void
    {
        $row = $site->edgeEnvVars()->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)->where('key', $key)->first()
            ?? new EdgeSiteEnvVar(['site_id' => $site->id, 'key' => $key, 'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION]);
        $row->value = $value;
        $row->save();
    }
}
