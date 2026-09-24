<?php

namespace App\Providers;

use App\Listeners\SyncBillingOnSubscriptionWebhook;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\StatusPage;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionItem;
use App\Modules\Billing\Observers\SiteBillingObserver;
use App\Modules\Edge\Services\CloudflareEdgeDelivery;
use App\Modules\Edge\Services\EdgeArtifactPublisher;
use App\Modules\Edge\Services\EdgeDeliveryContextResolver;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use App\Modules\Edge\Services\RuntimeDetection\GitCloner;
use App\Modules\Edge\Services\RuntimeDetection\GoRuntimeDetector;
use App\Modules\Edge\Services\RuntimeDetection\NodeRuntimeDetector;
use App\Modules\Edge\Services\RuntimeDetection\PhpRuntimeDetector;
use App\Modules\Edge\Services\RuntimeDetection\ProcessGitCloner;
use App\Modules\Edge\Services\RuntimeDetection\PythonRuntimeDetector;
use App\Modules\Edge\Services\RuntimeDetection\RubyRuntimeDetector;
use App\Modules\Edge\Services\RuntimeDetection\RuntimeDetectionEngine;
use App\Modules\Edge\Services\RuntimeDetection\StaticRuntimeDetector;
use App\Modules\Edge\Support\EdgeFilesystemRegistrar;
use App\Modules\Edge\Support\EdgePlatformCredentials;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Policies\IncidentPolicy;
use App\Policies\NotificationChannelPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\ProviderCredentialPolicy;
use App\Policies\ServerPolicy;
use App\Policies\SitePolicy;
use App\Policies\StatusPagePolicy;
use App\Policies\TeamPolicy;
use App\Policies\WorkspacePolicy;
use App\Routing\ProjectUrlGenerator;
use App\Services\Sites\EnsuresDefaultUptimeMonitors;
use App\Services\Sites\RepositoryWebhookProvisioner;
use App\Services\Sites\TestingHostnameProvisioner;
use App\Support\Config\ConfigDirectoryAliases;
use App\Support\Sites\SiteRegistry;
use App\Support\Workspaces\WorkspaceRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;
use Livewire\Blaze\Blaze;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        ConfigDirectoryAliases::apply();

        $this->app->extend('url', function ($url, $app) {
            if ($url instanceof ProjectUrlGenerator) {
                return $url;
            }

            $generator = new ProjectUrlGenerator(
                $app['router']->getRoutes(),
                $app['request'],
                config('app.asset_url'),
            );

            $session = (new \ReflectionClass($url))->getProperty('sessionResolver');
            $key = (new \ReflectionClass($url))->getProperty('keyResolver');
            if ($session->getValue($url) !== null) {
                $generator->setSessionResolver($session->getValue($url));
            }
            if ($key->getValue($url) !== null) {
                $generator->setKeyResolver($key->getValue($url));
            }

            return $generator;
        });

        // Scoped (reset per request/job) so its per-instance identity memo
        // dedupes the repeated social_accounts/git_provider_tokens lookups a
        // single site render fans out, without caching stale models across
        // jobs in a long-lived queue worker.
        $this->app->scoped(GitIdentityResolver::class);

        // Scoped: SitePolicy::update() authorizes the same site as several
        // distinct model instances in one render (page, workspace chrome,
        // command palette), each lazy-loading $site->workspace and then
        // $workspace->organization. Resolving through one shared Workspace
        // instance per id collapses both PK lookups to a single query.
        $this->app->scoped(WorkspaceRegistry::class);

        // Scoped: the edge workspace stacks sibling panels that each resolved
        // the same Site by id. Panels needing post-write state still call
        // ->fresh().
        $this->app->scoped(SiteRegistry::class);

        $this->app->singleton(RuntimeDetectionEngine::class, function ($app) {
            return new RuntimeDetectionEngine($app->tagged('site.runtime.detectors'));
        });

        $this->app->tag([
            PhpRuntimeDetector::class,
            NodeRuntimeDetector::class,
            PythonRuntimeDetector::class,
            RubyRuntimeDetector::class,
            GoRuntimeDetector::class,
            StaticRuntimeDetector::class,
        ], 'site.runtime.detectors');

        $this->app->bind(GitCloner::class, ProcessGitCloner::class);

        $this->app->singleton(EdgeArtifactPublisher::class);
        $this->app->singleton(EdgeHostMapPublisher::class);
        $this->app->singleton(EdgeDeliveryContextResolver::class);
        $this->app->singleton(CloudflareEdgeDelivery::class);
        $this->app->singleton(EdgeFilesystemRegistrar::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Blaze::optimize()
            ->in(resource_path('views/components/spinner.blade.php'), memo: true)
            ->in(resource_path('views/components/application-logo.blade.php'), memo: true)
            ->in(resource_path('views/components/input-error.blade.php'), memo: true)
            ->in(resource_path('views/components/oauth-provider-icon.blade.php'), memo: true)
            ->in(resource_path('views/components/credentials-provider-icon.blade.php'), memo: true);

        // A surface whose flag is off should read as "not here", not as a
        // malformed request — Pennant's default 400. Matches the app's own
        // RequiresFeature trait, which aborts 404.
        EnsureFeaturesAreActive::whenInactive(
            fn () => abort(404),
        );

        DevCommands::artisan('schedule:work');

        $this->registerEdgeR2FilesystemDisk();

        $this->discardCorruptedViteHotFile();

        // Models extracted into app/Modules/<Domain>/Models keep their factories
        // in database/factories/ (namespace Database\Factories). Laravel's default
        // resolver would look for Database\Factories\Modules\<Domain>\Models\<X>Factory;
        // map module models back to the flat Database\Factories\<X>Factory.
        Factory::guessFactoryNamesUsing(function (string $modelName): string {
            $relative = Str::startsWith($modelName, 'App\\Models\\')
                ? Str::after($modelName, 'App\\Models\\')
                : (str_starts_with($modelName, 'App\\Modules\\')
                    ? class_basename($modelName)
                    : Str::after($modelName, 'App\\'));

            return 'Database\\Factories\\'.$relative.'Factory';
        });

        Cashier::useCustomerModel(Organization::class);
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useSubscriptionItemModel(SubscriptionItem::class);

        Event::listen(WebhookReceived::class, SyncBillingOnSubscriptionWebhook::class);

        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(Server::class, ServerPolicy::class);
        Gate::policy(Site::class, SitePolicy::class);
        Gate::policy(ProviderCredential::class, ProviderCredentialPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);
        Gate::policy(NotificationChannel::class, NotificationChannelPolicy::class);
        Gate::policy(Workspace::class, WorkspacePolicy::class);
        Gate::policy(StatusPage::class, StatusPagePolicy::class);
        Gate::policy(Incident::class, IncidentPolicy::class);

        Gate::define('manageNotificationChannels', function (User $user, User|Organization|Team $owner): bool {
            if ($owner instanceof User) {
                return $user->id === $owner->id;
            }
            if ($owner instanceof Organization) {
                return $owner->hasAdminAccess($user);
            }

            return $owner->userCanManageNotificationChannels($user);
        });

        Gate::define('viewNotificationChannels', function (User $user, User|Organization|Team $owner): bool {
            if ($owner instanceof User) {
                return $user->id === $owner->id;
            }
            if ($owner instanceof Organization) {
                return $owner->hasAdminAccess($user);
            }

            return $owner->organization->hasMember($user) && $owner->hasMember($user);
        });

        Gate::define('viewPlatformAdmin', function (?User $user): bool {
            if ($user === null) {
                return false;
            }

            if (app()->environment(['local', 'testing'])) {
                return true;
            }

            $raw = (string) config('admin.allowed_emails', '');
            $allowed = array_values(array_filter(array_map('trim', explode(',', $raw))));

            if ($allowed === []) {
                return false;
            }

            return in_array($user->email, $allowed, true);
        });

        /*
         * Laravel Pulse registers viewPulse as local-only; override after all providers so
         * platform admins match Horizon /admin access (see config/admin.php).
         */
        $this->app->booted(function (): void {
            Gate::define('viewPulse', function (?User $user): bool {
                return $user !== null && Gate::forUser($user)->allows('viewPlatformAdmin');
            });
        });

        Site::observe(SiteBillingObserver::class);

        Site::created(function (Site $site): void {
            rescue(
                fn () => app(EnsuresDefaultUptimeMonitors::class)->ensure($site),
                report: false,
            );
        });

        Site::deleting(function (Site $site): void {
            rescue(
                fn () => app(RepositoryWebhookProvisioner::class)->disable($site),
                report: false,
            );
            $site->loadMissing(['previewDomains']);
            // Remove the managed preview/testing DNS record at the provider
            // BEFORE dropping the previewDomains rows — the teardown reads the
            // hostname/zone/record id off those rows, so deleting them first
            // would orphan the live DNS record (and a re-created same-slug site
            // would inherit a stale A record).
            rescue(
                fn () => app(TestingHostnameProvisioner::class)->delete($site),
                report: false,
            );
            $site->previewDomains()->delete();
        });

        RateLimiter::for('api', function (Request $request) {
            $token = $request->attributes->get('api_token');

            return Limit::perMinute(60)->by($token ? 'api:'.$token->id : $request->ip());
        });

        // Edge surface gets a higher ceiling — log tailing + ad-hoc
        // deploys are chatty by design, and a typical CI run can fire
        // 20–30 calls in quick succession (lint + deploy + poll). Keyed
        // by token id so one chatty token can't starve another's
        // budget. Falls back to IP when called pre-auth (shouldn't
        // happen post-`auth.api` but defensive).
        RateLimiter::for('edge-api', function (Request $request) {
            $token = $request->attributes->get('api_token');

            return Limit::perMinute(600)->by($token ? 'edge-api:'.$token->id : 'edge-api-ip:'.$request->ip());
        });

        // Creating and tearing down sites that provision infrastructure. Keyed
        // by ORGANIZATION rather than token: quota bounds how many sites can
        // exist, but it does not bound churn — a failed create consumes no
        // quota while still calling the provider, and a create/delete loop
        // stays under the ceiling forever. Deliberately not on `edge-api`,
        // which is sized for log polling.
        RateLimiter::for('site-create', function (Request $request) {
            $organization = $request->attributes->get('api_organization');
            $key = $organization !== null
                ? 'site-create:'.$organization->id
                : 'site-create-ip:'.$request->ip();

            return Limit::perMinute(10)->by($key);
        });

        RateLimiter::for('site-webhook', function (Request $request) {
            $site = $request->route('site');
            $key = $site instanceof Site ? 'wh:'.$site->id : 'wh-ip:'.$request->ip();

            return Limit::perMinute((int) config('sites.webhook_max_attempts_per_minute', 30))->by($key);
        });

        // Per-request log POSTs from deployed edge sites. Keyed by site so one
        // busy site can't starve another; generous because it fires once per
        // request served. Over the limit the fire-and-forget POST just 429s and
        // the row is dropped.
        RateLimiter::for('function-log-ingest', function (Request $request) {
            $site = $request->route('site');
            $key = $site instanceof Site ? 'fli:'.$site->id : 'fli-ip:'.$request->ip();

            return Limit::perMinute((int) config('sites.function_log_ingest_per_minute', 1000))->by($key);
        });
    }

    /**
     * Remove public/hot when its contents are not a plain http(s) dev-server URL.
     * Pasting colored terminal output into hot (or .env) produces garbage; the browser then
     * resolves asset URLs relative to the app origin and requests fail with mangled paths.
     */
    private function discardCorruptedViteHotFile(): void
    {
        $path = public_path('hot');

        if (! is_file($path)) {
            return;
        }

        $line = trim((string) file_get_contents($path));

        if ($line === '' || ! preg_match('/\Ahttps?:\/\//i', $line)) {
            @unlink($path);
        }
    }

    private function registerEdgeR2FilesystemDisk(): void
    {
        $cfg = config('edge.r2');
        $bucket = is_string($cfg['bucket'] ?? null) ? trim($cfg['bucket']) : '';
        if ($bucket === '') {
            return;
        }

        config([
            'filesystems.disks.edge_r2' => [
                'driver' => 's3',
                'key' => $cfg['key'],
                'secret' => $cfg['secret'],
                'region' => $cfg['region'],
                'bucket' => $bucket,
                'endpoint' => EdgePlatformCredentials::r2Endpoint(),
                'use_path_style_endpoint' => $cfg['use_path_style_endpoint'],
                'throw' => false,
                'report' => false,
            ],
        ]);
    }
}
