<?php

namespace App\Models;

use App\Enums\SiteType;
use App\Models\Concerns\Site\DerivesWorkerEnvironment;
use App\Models\Concerns\Site\HasSiteRelationships;
use App\Models\Concerns\Site\ManagesEdgeHosting;
use App\Models\Concerns\Site\ResolvesSiteHostnames;
use App\Models\Concerns\Site\ResolvesSiteRuntime;
use App\Models\Concerns\Site\ResolvesSiteUrls;
use App\Models\Concerns\Site\TracksProvisioningStatus;
use App\Support\Sites\SiteRelationPurger;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property ?string $server_id
 * @property ?string $user_id
 * @property ?string $organization_id
 * @property ?string $workspace_id
 * @property ?string $project_id
 * @property ?string $active_deploy_pipeline_id
 * @property ?string $parent_site_id
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path
 * @property SiteType $type
 * @property ?string $document_root
 * @property ?string $repository_path
 * @property ?string $runtime
 * @property ?string $runtime_version
 * @property ?string $database_engine
 * @property ?int $app_port
 * @property ?string $internal_port
 * @property ?string $build_command
 * @property ?string $start_command
 * @property string $status
 * @property string $ssl_status
 * @property ?Carbon $nginx_installed_at
 * @property ?Carbon $ssl_installed_at
 * @property ?Carbon $last_deploy_at
 * @property ?Carbon $suspended_at
 * @property ?string $suspended_reason
 * @property ?Carbon $scheduled_deletion_at
 * @property ?string $git_repository_url
 * @property ?string $git_branch
 * @property ?string $git_deploy_key_private
 * @property ?string $git_deploy_key_public
 * @property ?string $webhook_secret
 * @property ?list<string> $webhook_allowed_ips
 * @property ?string $post_deploy_command
 * @property ?string $deploy_script_id
 * @property string $deploy_strategy
 * @property string|null $deploy_method
 * @property int $releases_to_keep smallint in the schema
 * @property ?string $nginx_extra_raw
 * @property bool $engine_http_cache_enabled
 * @property ?string $octane_port
 * @property bool $laravel_scheduler
 * @property bool $restart_supervisor_programs_after_deploy
 * @property string $deployment_environment
 * @property ?string $php_fpm_user
 * @property ?string $env_file_content
 * @property ?Carbon $env_synced_at
 * @property ?string $env_cache_origin
 * @property ?string $env_file_path
 * @property ?string $container_image
 * @property ?string $container_registry
 * @property ?string $container_port
 * @property ?string $container_backend
 * @property ?string $container_backend_id
 * @property ?string $container_region
 * @property string|null $serverless_backend
 * @property ?string $serverless_provider_credential_id
 * @property ?string $edge_backend
 * @property ?string $edge_backend_id
 * @property ?string $edge_provider_credential_id
 * @property ?array<string, mixed> $meta
 * @property ?string $dns_provider_credential_id
 * @property ?string $dns_zone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ?string $php_version
 * @property-read int $code_action_count
 */
class Site extends Model
{
    use DerivesWorkerEnvironment;

    /** @use HasFactory<SiteFactory> */
    use HasFactory, HasUlids;

    use HasSiteRelationships;
    use ManagesEdgeHosting;
    use ResolvesSiteHostnames;
    use ResolvesSiteRuntime;
    use ResolvesSiteUrls;
    use TracksProvisioningStatus;

    public const STATUS_PENDING = 'pending';

    public const STATUS_NGINX_ACTIVE = 'nginx_active';

    public const STATUS_APACHE_ACTIVE = 'apache_active';

    public const STATUS_CADDY_ACTIVE = 'caddy_active';

    public const STATUS_OPENLITESPEED_ACTIVE = 'openlitespeed_active';

    public const STATUS_TRAEFIK_ACTIVE = 'traefik_active';

    public const STATUS_DOCKER_ACTIVE = 'docker_active';

    public const STATUS_DOCKER_CONFIGURED = 'docker_configured';

    public const STATUS_KUBERNETES_ACTIVE = 'kubernetes_active';

    public const STATUS_KUBERNETES_CONFIGURED = 'kubernetes_configured';

    public const STATUS_FUNCTIONS_CONFIGURED = 'functions_configured';

    public const STATUS_FUNCTIONS_ACTIVE = 'functions_active';

    /**
     * First deploy (or pre-live retry) failed. Mirrors {@see STATUS_EDGE_FAILED}
     * / {@see STATUS_CONTAINER_FAILED}: the Site row is kept for retry/delete,
     * but must not present as a healthy live function. Redeploy failures on an
     * already-active function leave {@see STATUS_FUNCTIONS_ACTIVE} alone.
     */
    public const STATUS_FUNCTIONS_FAILED = 'functions_failed';

    public const STATUS_CONTAINER_PROVISIONING = 'container_provisioning';

    public const STATUS_CONTAINER_ACTIVE = 'container_active';

    public const STATUS_CONTAINER_FAILED = 'container_failed';

    public const STATUS_EDGE_PROVISIONING = 'edge_provisioning';

    public const STATUS_EDGE_ACTIVE = 'edge_active';

    public const STATUS_EDGE_FAILED = 'edge_failed';

    /**
     * Serverless function resource limits. These map onto the OpenWhisk
     * action `limits` block DigitalOcean Functions is built on, and are
     * applied to the action on the next deploy.
     *
     * @var array<int, int>
     */
    /**
     * Runtime profiles that make a Site a serverless function rather than a VM
     * site. The `meta->runtime_profile` value is the only thing distinguishing
     * the two, so both the row predicate ({@see usesFunctionsRuntime()}) and
     * every `whereIn` that scopes a function list read this one list.
     *
     * @var list<string>
     */
    public const SERVERLESS_RUNTIME_PROFILES = ['digitalocean_functions_web', 'aws_lambda_bref_web'];

    public const SERVERLESS_MEMORY_OPTIONS_MB = [128, 256, 512, 1024];

    public const SERVERLESS_DEFAULT_MEMORY_MB = 512;

    public const SERVERLESS_DEFAULT_TIMEOUT_MS = 60000;

    public const SERVERLESS_MIN_TIMEOUT_MS = 1000;

    public const SERVERLESS_MAX_TIMEOUT_MS = 900000;

    public const SERVERLESS_DEFAULT_CONCURRENCY = 1;

    public const SERVERLESS_MAX_CONCURRENCY = 50;

    /**
     * DigitalOcean Functions stores `limits.logs` in kilobytes and caps
     * captured console output at 256 KB per invocation.
     */
    public const SERVERLESS_DEFAULT_LOGS_KB = 256;

    public const SERVERLESS_MIN_LOGS_KB = 1;

    public const SERVERLESS_MAX_LOGS_KB = 256;

    /**
     * Site row exists, scaffold pipeline (PR 5/6) is in flight.
     * Distinct from container_provisioning so Container vs Scaffold
     * journeys don't share states or audit shapes.
     */
    public const STATUS_SCAFFOLDING = 'scaffolding';

    public const STATUS_SCAFFOLD_FAILED = 'scaffold_failed';

    public const STATUS_CUSTOM_ACTIVE = 'custom_active';

    /**
     * Bare VM site created by the choose-app flow (config/dply.php
     * `choose_app_enabled`). Domain + server are set, but type / runtime /
     * document_root are not yet chosen — the user picks an application on
     * sites.choose-app, which transitions the site into its real status.
     * See docs/CHOOSE_APP_FLOW.md.
     */
    public const STATUS_AWAITING_APP = 'awaiting_app';

    public const STATUS_ERROR = 'error';

    public const SSL_NONE = 'none';

    public const SSL_PENDING = 'pending';

    public const SSL_ACTIVE = 'active';

    public const SSL_FAILED = 'failed';

    protected $fillable = [
        'server_id',
        'user_id',
        'organization_id',
        'workspace_id',
        'dns_provider_credential_id',
        'dns_zone',
        'name',
        'slug',
        'logo_path',
        'type',
        'document_root',
        'repository_path',
        'runtime',
        'runtime_version',
        'database_engine',
        'app_port',
        'internal_port',
        'build_command',
        'start_command',
        'status',
        'ssl_status',
        'nginx_installed_at',
        'ssl_installed_at',
        'last_deploy_at',
        'suspended_at',
        'suspended_reason',
        'scheduled_deletion_at',
        'git_repository_url',
        'git_branch',
        'git_deploy_key_private',
        'git_deploy_key_public',
        'webhook_secret',
        'webhook_allowed_ips',
        'post_deploy_command',
        'deploy_script_id',
        'deploy_strategy',
        'deploy_method',
        'parent_site_id',
        'releases_to_keep',
        'nginx_extra_raw',
        'engine_http_cache_enabled',
        'octane_port',
        'laravel_scheduler',
        'restart_supervisor_programs_after_deploy',
        'deployment_environment',
        'php_fpm_user',
        'env_file_content',
        'env_synced_at',
        'env_cache_origin',
        'env_file_path',
        'container_image',
        'container_registry',
        'container_port',
        'container_backend',
        'container_backend_id',
        'container_region',
        'serverless_backend',
        'serverless_provider_credential_id',
        'edge_backend',
        'edge_backend_id',
        'edge_provider_credential_id',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SiteType::class,
            'git_deploy_key_private' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'webhook_allowed_ips' => 'array',
            'env_file_content' => 'encrypted',
            'env_synced_at' => 'datetime',
            'meta' => 'array',
            'app_port' => 'integer',
            'laravel_scheduler' => 'boolean',
            'restart_supervisor_programs_after_deploy' => 'boolean',
            'engine_http_cache_enabled' => 'boolean',
            'nginx_installed_at' => 'datetime',
            'ssl_installed_at' => 'datetime',
            'last_deploy_at' => 'datetime',
            'suspended_at' => 'datetime',
            'scheduled_deletion_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Keep the legacy `engine_http_cache_enabled` column in sync with
        // `meta['caching']` so existing direct-column readers (5 in
        // NginxSiteConfigBuilder / SiteNginxProvisioner) keep working until
        // the column is dropped in a follow-up release. The reverse mapping
        // lives in {@see Site::cachingConfig()}, which falls back to the
        // boolean when `meta['caching']` is absent.
        static::saving(function (Site $site): void {
            $meta = $site->meta ?? [];
            $caching = $meta['caching'] ?? null;
            if (! is_array($caching)) {
                return;
            }
            $enabled = (bool) ($meta['caching']['enabled'] ?? false);
            $methods = $meta['caching']['methods'] ?? [];
            $hasNginxHttp = is_array($methods) && in_array('nginx_http', $methods, true);
            $site->engine_http_cache_enabled = $enabled && $hasNginxHttp;
        });

        static::creating(function (Site $site): void {
            if (empty($site->slug)) {
                $site->slug = Str::slug($site->name) ?: 'site';
            }
            if ($site->server_id && ! $site->organization_id) {
                $server = Server::query()->find($site->server_id);
                if ($server) {
                    $site->organization_id = $server->organization_id;
                }
            }
            if ($site->workspace_id === null && $site->server_id) {
                $server = Server::query()->find($site->server_id);
                if ($server?->workspace_id) {
                    $site->workspace_id = $server->workspace_id;
                }
            }
            if (data_get($site->getAttributes(), 'project_id') === null && $site->organization_id && $site->user_id) {
                // Auto-create a BYO-site Project only when we can satisfy its NOT NULL
                // owners (organization_id + user_id). In-memory test fixtures and other
                // edge cases that lack those skip the auto-creation rather than
                // crashing the host save() — the operator can attach a project later.
                $project = Project::query()->create([
                    'organization_id' => $site->organization_id,
                    'user_id' => $site->user_id,
                    'name' => $site->name ?: 'Site',
                    'slug' => 'tmp-'.Str::lower(Str::random(20)),
                    'kind' => Project::KIND_BYO_SITE,
                ]);
                $site->project_id = $project->id;
            }
        });

        static::created(function (Site $site): void {
            if ($site->project_id !== null) {
                // The `creating` hook only auto-attaches a Project when org_id + user_id
                // are present; without a project we skip the rename pass rather than firing
                // an UPDATE against zero rows. Sites without a project attach one later.
                $site->project()->update([
                    'slug' => $site->slug.'-'.$site->id,
                    'name' => $site->name,
                ]);
            }

        });

        static::deleting(function (Site $site): void {
            // Purge relations the DB FK cascade can't reach — denormalised
            // site_id columns (error_events / app_logs / …) and polymorphic
            // links (console_actions, notification_subscriptions, …) — so a
            // deleted site leaves no orphaned rows (esp. its Errors stream).
            try {
                app(SiteRelationPurger::class)->purge($site);
            } catch (\Throwable $e) {
                Log::warning('Site::deleting relation purge failed', [
                    'site_id' => $site->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }

        });

        static::deleted(function (Site $site): void {
            if ($site->project_id) {
                Project::query()->whereKey($site->project_id)->delete();
            }
        });
    }

    /** dply runs the function on its own managed FaaS account (dply pays the provider). */
    public const SERVERLESS_BACKEND_DPLY = 'dply_serverless';

    /** The customer's connected provider account runs (and is billed for) the function. */
    public const SERVERLESS_BACKEND_BYO = 'org_digitalocean';

    /**
     * URL the container deployment is reachable at, set by the
     * provisioner once the backend reports an "ingress" hostname
     * (DO App Platform default ondigitalocean.app, App Runner's
     * default *.awsapprunner.com).
     */

    /**
     * Laravel Octane application server choices (see `php artisan octane:start --help`).
     *
     * @var list<string>
     */
    public const OCTANE_SERVERS = ['swoole', 'roadrunner', 'frankenphp'];

    public function resolveRouteBinding($value, $field = null): ?static
    {
        // Accepts slug (human-readable, used by CLI) or primary key (ULID,
        // used by web routes). Tries slug first so CLI commands like
        // `dply sites:show my-site` resolve correctly without changing how
        // web URL generation works (which still uses the primary key).
        $resolved = static::query()
            ->where('slug', $value)
            ->orWhere($this->getKeyName(), $value)
            ->first();

        return $resolved instanceof static ? $resolved : null;
    }

    public function ensureUniqueSlug(): void
    {
        $base = $this->slug;
        $i = 1;
        while ($this->slugTaken()) {
            $this->slug = $base.'-'.$i;
            $i++;
        }
    }

    /**
     * Cloud (container) apps.
     *
     * The predicate is `type = Container` OR a non-null `container_backend`:
     * sites created through the Cloud surface carry the type, while some
     * imported/legacy rows only ever got the backend column. Extracted from
     * App\Livewire\Cloud\Index so callers stop re-deriving it — getting one
     * half of the OR wrong silently hides apps.
     *
     * @param  Builder<Site>  $query
     */
    public function scopeCloudApps(Builder $query): void
    {
        $query->where(function ($q): void {
            $q->where('type', SiteType::Container)
                ->orWhereNotNull('container_backend');
        });
    }

    /**
     * Hide site-sourced worker-fleet replicas from the Sites inventory.
     *
     * @param  Builder<Site>  $query
     */
    public function scopeVisibleInSiteIndex(Builder $query): void
    {
        $query->whereRaw("coalesce(meta->>'fleet_replica_of_site_id', '') = ''");
    }

    public function isFleetReplica(): bool
    {
        return filled(data_get($this->meta, 'fleet_replica_of_site_id'));
    }

    /**
     * Hidden worker-host copies of this site (same repo, drain the same queue).
     *
     * `static`, not `Site`: the query is built with `static::query()`, and
     * Eloquent's Collection is invariant in TModel, so a Collection<int, static>
     * is not a Collection<int, Site>. Site is subclassed (PhpStanTraitHarness),
     * so this is a real distinction rather than a formality.
     *
     * @return Collection<int, static>
     */
    public function fleetReplicaSites()
    {
        if ($this->organization_id === null || $this->isFleetReplica()) {
            return static::query()->whereRaw('1 = 0')->get();
        }

        return static::query()
            ->where('organization_id', $this->organization_id)
            ->where('meta->fleet_replica_of_site_id', $this->id)
            ->get();
    }

    protected function slugTaken(): bool
    {
        $q = static::query()
            ->where('server_id', $this->server_id)
            ->where('slug', $this->slug);
        if ($this->exists) {
            $q->whereKeyNot($this->getKey());
        }

        return $q->exists();
    }
}
