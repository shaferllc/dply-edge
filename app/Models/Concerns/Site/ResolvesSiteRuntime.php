<?php

declare(strict_types=1);

namespace App\Models\Concerns\Site;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\RuntimeDetection\PhpRuntimeDetector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * Extracted from {@see Site}. Composed back into the model via `use`.
 *
 * @property array<string, mixed> $meta
 * @property ?string $runtime
 * @property ?string $runtime_version
 * @property ?string $database_engine
 * @property ?string $server_id
 * @property ?string $app_port
 * @property ?string $octane_port
 * @property ?string $container_backend
 * @property string $edge_backend
 * @property SiteType $type
 * @property-read ?Server $server
 */
trait ResolvesSiteRuntime
{
    /**
     * Returns the version of whatever runtime this site uses.
     *
     * Prefers the new `runtime_version` column; falls back to `php_version`
     * for legacy/PHP rows that haven't been re-saved since the column was
     * introduced.
     */
    public function runtimeVersion(): ?string
    {
        if (filled($this->runtime_version)) {
            return $this->runtime_version;
        }

        return null;
    }

    /**
     * Returns the canonical runtime key for this site (php/node/python/
     * ruby/go/static).
     *
     * Prefers the new `runtime` column; falls back to the existing `type`
     * enum for rows that predate the column. The fallback only covers the
     * three values the form historically supported (php/node/static) —
     * python/ruby/go sites can only exist via the new code path.
     */
    public function runtimeKey(): ?string
    {
        if (filled($this->runtime)) {
            return $this->runtime;
        }

        // `type` is nullable — an Edge site row can carry neither, and reading
        // ->value off null fatals rather than returning "no runtime known".
        return $this->type?->value;
    }

    /**
     * The PHP version this site runs on, when the runtime is PHP.
     *
     * Reads from the new `runtime_version` column (canonical source per
     * the strategy memo's "drop php_version column entirely" decision)
     * and falls back to the legacy `php_version` column for rows that
     * predate the column drop. Returns null for non-PHP runtimes so
     * call sites can distinguish "not a PHP site" from "PHP version
     * unknown".
     */
    public function phpVersion(): ?string
    {
        if ($this->runtimeKey() !== 'php') {
            return null;
        }

        return filled($this->runtime_version) ? $this->runtime_version : null;
    }

    /**
     * Back-compat shim for the dropped `php_version` column.
     *
     * The strategy memo's "drop php_version column entirely" decision
     * removed the underlying column, but a lot of test code (and
     * possibly third-party callers) still passes `'php_version' => '8.3'`
     * to factory `->create([...])` calls or to `Site::query()->update()`.
     * Routing those through to `runtime_version` keeps the old call
     * shape working while the canonical column is the new one.
     *
     * Reads return runtime_version when the runtime is PHP, null otherwise
     * — matches phpVersion()'s semantics so consumers can call either.
     */
    public function setPhpVersionAttribute(mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! filled($this->runtime)) {
            $this->runtime = 'php';
        }
        $this->runtime_version = (string) $value;
    }

    public function getPhpVersionAttribute(): ?string
    {
        return $this->phpVersion();
    }

    public function runtimeProfileLabel(): string
    {
        return match ($this->runtimeProfile()) {
            'docker_web' => __('Docker'),
            'kubernetes_web' => __('Kubernetes'),
            'digitalocean_functions_web' => __('Functions'),
            'aws_lambda_bref_web' => __('AWS Lambda'),
            'vm_web' => __('BYO VM'),
            default => (string) str($this->runtimeProfile())->replace('_', ' ')->title(),
        };
    }

    public function runtimeExecutionModeLabel(): string
    {
        return match ($this->runtimeTargetMode()) {
            'docker' => __('Container'),
            'kubernetes' => __('Kubernetes'),
            'serverless' => __('Serverless'),
            default => __('VM'),
        };
    }

    /**
     * App/stack detection from persisted meta. Priority matches
     * {@see DeploymentSecretInventory::detectedFramework}:
     * Docker → Kubernetes → serverless → VM (composer.json on deploy).
     *
     * @return array{
     *     source: 'docker'|'kubernetes'|'serverless'|'vm',
     *     framework: string,
     *     language: string,
     *     confidence?: string,
     *     warnings?: list<string>,
     *     detected_files?: list<string>,
     *     laravel_octane?: bool,
     *     laravel_horizon?: bool,
     *     laravel_pulse?: bool,
     *     laravel_reverb?: bool
     * }|null
     */
    public function resolvedRuntimeAppDetection(): ?array
    {
        $meta = $this->meta ?? [];

        $candidates = [
            ['source' => 'docker', 'blob' => data_get($meta, 'docker_runtime.detected')],
            ['source' => 'kubernetes', 'blob' => data_get($meta, 'kubernetes_runtime.detected')],
            ['source' => 'serverless', 'blob' => data_get($meta, 'serverless.detected_runtime') ?: data_get($meta, 'serverless.detected')],
            ['source' => 'vm', 'blob' => data_get($meta, 'vm_runtime.detected')],
            // Edge apps record the framework their build detected; without this
            // a Laravel container app never shows its Laravel-only tools.
            ['source' => 'edge', 'blob' => filled(data_get($meta, 'edge.build.framework')) ? [
                'framework' => (string) data_get($meta, 'edge.build.framework'),
                'language' => ['laravel' => 'php', 'rails' => 'ruby'][strtolower((string) data_get($meta, 'edge.build.framework'))] ?? '',
            ] : null],
        ];

        foreach ($candidates as $candidate) {
            $blob = $candidate['blob'];
            if (! is_array($blob) || $blob === []) {
                continue;
            }

            if (! $this->runtimeAppDetectionIsMeaningful($blob)) {
                continue;
            }

            $out = [
                'source' => $candidate['source'],
                'framework' => (string) ($blob['framework'] ?? 'unknown'),
                'language' => (string) ($blob['language'] ?? 'unknown'),
            ];

            if (isset($blob['confidence']) && is_string($blob['confidence']) && $blob['confidence'] !== '') {
                $out['confidence'] = $blob['confidence'];
            }

            if (isset($blob['warnings']) && is_array($blob['warnings'])) {
                $warnings = array_values(array_filter($blob['warnings'], static fn ($w) => is_string($w) && $w !== ''));
                if ($warnings !== []) {
                    $out['warnings'] = $warnings;
                }
            }

            if (isset($blob['detected_files']) && is_array($blob['detected_files'])) {
                $files = array_values(array_filter($blob['detected_files'], static fn ($f) => is_string($f) && $f !== ''));
                if ($files !== []) {
                    $out['detected_files'] = $files;
                }
            }

            foreach (['laravel_octane', 'laravel_horizon', 'laravel_pulse', 'laravel_reverb'] as $laravelPkgKey) {
                if (! empty($blob[$laravelPkgKey])) {
                    $out[$laravelPkgKey] = true;
                }
            }

            return $out;
        }

        return null;
    }

    public function isLaravelFrameworkDetected(): bool
    {
        return $this->resolvedRuntimeFrameworkKey() === 'laravel';
    }

    public function isRailsFrameworkDetected(): bool
    {
        return $this->resolvedRuntimeFrameworkKey() === 'rails';
    }

    /**
     * True when the site's detected runtime app is WordPress (per
     * {@see PhpRuntimeDetector}),
     * OR when the site was scaffolded with the WordPress framework
     * pipeline (Q14 — gates the WordPress Settings section).
     */
    public function isWordPressDetected(): bool
    {
        if ($this->resolvedRuntimeFrameworkKey() === 'wordpress') {
            return true;
        }

        $scaffoldFramework = $this->meta['scaffold']['framework'] ?? null;

        return is_string($scaffoldFramework) && strtolower($scaffoldFramework) === 'wordpress';
    }

    /**
     * True when this site is a "manage-in-place" CMS — scaffolded WordPress or
     * Drupal, which dply installs and maintains on the box rather than deploying
     * from a Git repo. The Repository surfaces are hidden for these (there's no
     * developer-owned repo; the install itself is the source of truth).
     */
    public function isManageInPlaceCms(): bool
    {
        $scaffoldFramework = strtolower((string) ($this->meta['scaffold']['framework'] ?? ''));

        return in_array($scaffoldFramework, ['wordpress', 'drupal'], true);
    }

    /**
     * @param  array<string, mixed>  $blob
     */
    private function runtimeAppDetectionIsMeaningful(array $blob): bool
    {
        $fw = strtolower(trim((string) ($blob['framework'] ?? '')));
        $lang = strtolower(trim((string) ($blob['language'] ?? '')));

        if ($fw !== '' && $fw !== 'unknown') {
            return true;
        }

        return $lang !== '' && $lang !== 'unknown';
    }

    private function resolvedRuntimeFrameworkKey(): string
    {
        $resolved = $this->resolvedRuntimeAppDetection();

        return $resolved !== null ? strtolower($resolved['framework']) : '';
    }

    public function usesFunctionsRuntime(): bool
    {
        return in_array($this->runtimeProfile(), Site::SERVERLESS_RUNTIME_PROFILES, true);
    }

    public function usesAwsLambdaRuntime(): bool
    {
        return $this->runtimeProfile() === 'aws_lambda_bref_web';
    }

    public function usesDockerRuntime(): bool
    {
        return $this->runtimeProfile() === 'docker_web';
    }

    public function usesKubernetesRuntime(): bool
    {
        return $this->runtimeProfile() === 'kubernetes_web';
    }

    /**
     * A long-running application server that listens on a localhost port we can
     * probe — Node/Python/Ruby/Go services and the JS/Python web frameworks.
     * Excludes static builds (served by the webserver, no live process) and PHP
     * (which is FPM, probed separately).
     */
    public function isLongRunningAppServer(): bool
    {
        if (in_array((string) $this->runtimeKey(), ['node', 'python', 'ruby', 'go'], true)) {
            return true;
        }

        $framework = $this->resolvedRuntimeFrameworkKey();

        return in_array($framework, [
            'rails',
            'nextjs',
            'nuxt',
            'node_generic',
            'django',
            'flask',
            'fastapi',
            'python_generic',
        ], true);
    }

    /**
     * Whether this site can use the site-scoped systemd Services workspace
     * (dply-site-{id}[-{name}].service). PHP/static and container/serverless
     * workloads use FPM, nginx, or Supervisor (Daemons) instead.
     */
    public static function supportsSystemdServices(Site $site, Server $server): bool
    {
        if (! $server->hostCapabilities()->supportsSsh()) {
            return false;
        }

        if ($site->usesFunctionsRuntime()
            || $site->usesDockerRuntime()
            || $site->usesKubernetesRuntime()) {
            return false;
        }

        return ! in_array((string) ($site->runtime ?? ''), ['php', 'static'], true);
    }

    public function usesContainerRuntime(): bool
    {
        return $this->type === SiteType::Container
            || in_array($this->container_backend, [
                'digitalocean_app_platform',
                'aws_app_runner',
                'dply_cloud',
            ], true);
    }

    public function usesEdgeRuntime(): bool
    {
        return filled($this->edge_backend);
    }

    /**
     * PHP / Laravel / Symfony rollout fields (Octane, PHP-FPM, scheduler, etc.).
     * Matches {@see Settings::shouldShowRuntimePhpRolloutFields()}.
     */
    public function shouldShowPhpOctaneRolloutSettings(): bool
    {
        $this->loadMissing('server');
        if ($this->server?->hostCapabilities()->supportsFunctionDeploy()) {
            return false;
        }

        $fw = $this->resolvedRuntimeFrameworkKey();

        return $this->type === SiteType::Php
            || in_array($fw, ['laravel', 'php_generic', 'symfony'], true);
    }

    /**
     * Heading for the Runtime "PHP process" block — only includes "Laravel" when Laravel is the detected framework.
     */
    public function runtimePhpProcessSectionTitle(): string
    {
        $fw = $this->resolvedRuntimeFrameworkKey();

        return match ($fw) {
            'laravel' => __('PHP process & Laravel'),
            'symfony' => __('PHP process & Symfony'),
            'wordpress' => __('PHP process'),
            'php_generic' => __('PHP process'),
            '' => __('PHP process'),
            default => __('PHP process (:stack)', ['stack' => (string) str($fw)->replace('_', ' ')->title()]),
        };
    }

    /**
     * Label for the per-minute cron / scheduler checkbox (word "Laravel" only when detection says Laravel).
     */
    public function runtimeSchedulerCheckboxLabel(): string
    {
        $fw = $this->resolvedRuntimeFrameworkKey();

        return $fw === 'laravel'
            ? __('Laravel scheduler (cron)')
            : __('Per-minute cron task');
    }

    /**
     * Helper text when Laravel is not the detected framework but the cron option is still shown (PHP site).
     */
    public function runtimeSchedulerCheckboxHelp(): ?string
    {
        $fw = $this->resolvedRuntimeFrameworkKey();

        return $fw === 'laravel'
            ? null
            : __('Adds `php artisan schedule:run` each minute. Enable only for Laravel apps that use the scheduler; leave off for Symfony, WordPress, and other stacks.');
    }

    /**
     * Full single-line label for the scheduler checkbox on Deploy → Rollout (includes schedule:run hint for Laravel).
     */
    public function runtimeSchedulerRolloutFormLabel(): string
    {
        $fw = $this->resolvedRuntimeFrameworkKey();

        return $fw === 'laravel'
            ? __('Laravel scheduler (schedule:run every minute via server crontab)')
            : __('Per-minute cron task (via server crontab)');
    }

    /**
     * Whether to surface the Laravel scheduler convenience toggle (the per-minute
     * `php artisan schedule:run` cron) AND actually install it. A single rule
     * honoured by both the UI (visibility) and the deploy effect
     * ({@see ServerCronSynchronizer}) so they can't drift.
     *
     * Shown for PHP sites unless we're CONFIDENT the stack can't use it: hidden
     * only for non-PHP runtimes and for frameworks that have no `artisan`
     * (WordPress, Symfony, Drupal…). When detection is empty / `php_generic`
     * (e.g. a never-deployed or unrecognised PHP repo) we err toward showing it,
     * since it could be a Laravel app whose detection hasn't run yet.
     */
    public function supportsLaravelScheduler(): bool
    {
        if ($this->runtimeKey() !== 'php') {
            return false;
        }

        // Framework signal from runtime detection OR scaffold meta (mirrors the
        // dual-source approach in isWordPressDetected()), so a scaffolded
        // WordPress/Symfony site is correctly excluded before its first deploy.
        $detected = $this->resolvedRuntimeFrameworkKey();
        $scaffolded = strtolower((string) ($this->meta['scaffold']['framework'] ?? ''));

        $confidentlyNonLaravel = ['wordpress', 'symfony', 'drupal', 'rails'];

        return ! in_array($detected, $confidentlyNonLaravel, true)
            && ! in_array($scaffolded, $confidentlyNonLaravel, true);
    }

    /**
     * Whether dply can manage per-minute crons on this site's host (mirrors
     * {@see WorkspaceCron::siteSupportsVmManagedCron()}).
     * Gates the "→ Cron" link shown when the Laravel scheduler toggle is hidden.
     */
    public function supportsVmManagedCron(): bool
    {
        $this->loadMissing('server');

        return (bool) $this->server?->hostCapabilities()->supportsSsh()
            && ! $this->usesFunctionsRuntime()
            && ! $this->usesDockerRuntime()
            && ! $this->usesKubernetesRuntime();
    }

    /**
     * Rails-specific fields (e.g. RAILS_ENV in meta).
     * Matches {@see Settings::shouldShowRailsRuntimeFields()}.
     */
    public function shouldShowRailsRuntimeSettings(): bool
    {
        $this->loadMissing('server');
        if ($this->server?->hostCapabilities()->supportsFunctionDeploy()) {
            return false;
        }

        return $this->resolvedRuntimeFrameworkKey() === 'rails';
    }

    public function octaneServer(): string
    {
        $meta = $this->meta ?? [];
        $lo = is_array($meta['laravel_octane'] ?? null) ? $meta['laravel_octane'] : [];
        $s = strtolower((string) ($lo['server'] ?? 'swoole'));

        return in_array($s, self::OCTANE_SERVERS, true) ? $s : 'swoole';
    }

    /**
     * Operator saved an Octane port under Runtime / Laravel settings.
     */
    public function isOctaneEnabled(): bool
    {
        return (bool) $this->octane_port;
    }

    /**
     * Local port for Reverb WebSocket server (Supervisor / reverse proxy); stored in meta.laravel_reverb.port.
     */
    public function reverbLocalPort(): int
    {
        $meta = $this->meta ?? [];
        $r = is_array($meta['laravel_reverb'] ?? null) ? $meta['laravel_reverb'] : [];
        $p = $r['port'] ?? 8080;

        return is_numeric($p) ? max(1, min(65535, (int) $p)) : 8080;
    }

    /**
     * URL path prefix for Laravel Echo + Reverb (default /app).
     */
    public function reverbWebSocketPath(): string
    {
        $meta = $this->meta ?? [];
        $r = is_array($meta['laravel_reverb'] ?? null) ? $meta['laravel_reverb'] : [];
        $p = trim((string) ($r['ws_path'] ?? '/app'));
        if ($p === '' || $p[0] !== '/') {
            return '/app';
        }
        if (! preg_match('#^/[a-zA-Z0-9/_\-]+$#', $p)) {
            return '/app';
        }

        return rtrim($p, '/') === '' ? '/app' : rtrim($p, '/');
    }

    public function horizonDashboardPath(): string
    {
        $meta = $this->meta ?? [];
        $h = is_array($meta['laravel_horizon'] ?? null) ? $meta['laravel_horizon'] : [];
        $p = trim((string) ($h['path'] ?? '/horizon'));
        if ($p === '' || $p[0] !== '/') {
            return '/horizon';
        }

        return preg_match('#^/[a-zA-Z0-9/_\-]+$#', $p) ? rtrim($p, '/') ?: '/horizon' : '/horizon';
    }

    public function pulseDashboardPath(): string
    {
        $meta = $this->meta ?? [];
        $h = is_array($meta['laravel_pulse'] ?? null) ? $meta['laravel_pulse'] : [];
        $p = trim((string) ($h['path'] ?? '/pulse'));
        if ($p === '' || $p[0] !== '/') {
            return '/pulse';
        }

        return preg_match('#^/[a-zA-Z0-9/_\-]+$#', $p) ? rtrim($p, '/') ?: '/pulse' : '/pulse';
    }

    /**
     * Suggested Supervisor command for Reverb; optional port override for form preview before save.
     */
    public function reverbSupervisorCommandLine(?int $portOverride = null): string
    {
        $p = $portOverride ?? $this->reverbLocalPort();

        return sprintf('php artisan reverb:start --host=0.0.0.0 --port=%d', max(1, min(65535, $p)));
    }

    /**
     * Supervisor command line for Octane (run with `directory` = deploy root, e.g. current release).
     */
    public function octaneSupervisorCommand(): string
    {
        $port = (int) ($this->octane_port ?? 0);
        if ($port < 1) {
            $port = 8000;
        }

        return sprintf(
            'php artisan octane:start --server=%s --host=127.0.0.1 --port=%d',
            $this->octaneServer(),
            (int) $port
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function runtimeTarget(): array
    {
        $meta = $this->meta ?? [];
        $target = $meta['runtime_target'] ?? null;

        if (is_array($target) && ($target['family'] ?? null)) {
            return $target;
        }

        return [
            'family' => $this->runtimeTargetFamily(),
            'platform' => $this->runtimeTargetPlatform(),
            'mode' => $this->runtimeTargetMode(),
            'provider' => $this->runtimeTargetProvider(),
            'status' => null,
            'logs' => [],
        ];
    }

    public function runtimeTargetFamily(): string
    {
        $target = is_array($this->meta['runtime_target'] ?? null) ? $this->meta['runtime_target'] : [];
        $family = $target['family'] ?? null;
        if (is_string($family) && $family !== '') {
            return $family;
        }

        if ($this->usesDockerRuntime()) {
            return match (true) {
                data_get($this->server?->meta, 'local_runtime.provider') === 'orbstack' => 'local_orbstack_docker',
                $this->server?->provider?->value === 'digitalocean' => 'digitalocean_docker',
                $this->server?->provider?->value === 'aws' => 'aws_docker',
                default => 'docker',
            };
        }

        if ($this->usesKubernetesRuntime()) {
            return match (true) {
                data_get($this->server?->meta, 'local_runtime.provider') === 'orbstack' => 'local_orbstack_kubernetes',
                $this->server?->provider?->value === 'digitalocean' => 'digitalocean_kubernetes',
                $this->server?->provider?->value === 'aws' => 'aws_kubernetes',
                default => 'kubernetes',
            };
        }

        if ($this->usesAwsLambdaRuntime()) {
            return 'aws_lambda';
        }

        if ($this->usesFunctionsRuntime()) {
            return 'digitalocean_functions';
        }

        return 'byo_vm';
    }

    public function runtimeTargetPlatform(): string
    {
        return match ($this->runtimeTargetFamily()) {
            'local_orbstack_docker', 'local_orbstack_kubernetes' => 'local',
            'digitalocean_docker', 'digitalocean_kubernetes', 'digitalocean_functions' => 'digitalocean',
            'aws_docker', 'aws_kubernetes', 'aws_lambda' => 'aws',
            default => 'byo',
        };
    }

    public function runtimeTargetProvider(): string
    {
        return match ($this->runtimeTargetPlatform()) {
            'local' => 'orbstack',
            'digitalocean' => 'digitalocean',
            'aws' => 'aws',
            default => 'byo',
        };
    }

    public function runtimeTargetMode(): string
    {
        return match ($this->runtimeTargetFamily()) {
            'local_orbstack_kubernetes', 'digitalocean_kubernetes', 'aws_kubernetes', 'kubernetes' => 'kubernetes',
            'local_orbstack_docker', 'digitalocean_docker', 'aws_docker', 'docker', 'byo_vm_docker' => 'docker',
            'digitalocean_functions', 'aws_lambda' => 'serverless',
            default => 'vm',
        };
    }

    public function usesLocalDockerHostRuntime(): bool
    {
        return in_array($this->runtimeTargetFamily(), [
            'local_orbstack_docker',
            'local_orbstack_kubernetes',
        ], true);
    }

    public function runtimeTargetLabel(): string
    {
        return match ($this->runtimeTargetFamily()) {
            'local_orbstack_docker' => 'Local Docker',
            'local_orbstack_kubernetes' => 'Local Kubernetes',
            'digitalocean_docker' => 'DigitalOcean Docker',
            'digitalocean_kubernetes' => 'DigitalOcean Kubernetes',
            'aws_docker' => 'AWS Docker',
            'aws_kubernetes' => 'AWS Kubernetes',
            'digitalocean_functions' => 'Functions',
            'aws_lambda' => 'AWS Lambda',
            default => 'BYO runtime',
        };
    }

    public function runtimeRepositorySubdirectory(): string
    {
        $meta = $this->meta ?? [];
        $subdirectory = data_get($meta, 'runtime_target.repository_subdirectory');

        if (! is_string($subdirectory) || trim($subdirectory) === '') {
            $subdirectory = $this->usesKubernetesRuntime()
                ? data_get($meta, 'kubernetes_runtime.repository_subdirectory')
                : data_get($meta, 'docker_runtime.repository_subdirectory');
        }

        return is_string($subdirectory) ? trim($subdirectory, '/') : '';
    }

    public function runtimeProfile(): string
    {
        $meta = $this->meta ?? [];
        $profile = $meta['runtime_profile'] ?? null;

        if ($profile !== null && $profile !== '') {
            return (string) $profile;
        }

        if ($this->server?->isDigitalOceanFunctionsHost()) {
            return 'digitalocean_functions_web';
        }

        if ($this->server?->isAwsLambdaHost()) {
            return 'aws_lambda_bref_web';
        }

        if ($this->server?->isDockerHost()) {
            return 'docker_web';
        }

        if ($this->server?->isKubernetesCluster()) {
            return 'kubernetes_web';
        }

        return 'vm_web';
    }
}
