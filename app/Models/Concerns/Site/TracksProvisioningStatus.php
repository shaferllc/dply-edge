<?php

declare(strict_types=1);

namespace App\Models\Concerns\Site;

use App\Enums\SiteType;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Extracted from {@see Site}. Composed back into the model via `use`.
 *
 * @property array<string, mixed> $meta
 * @property string $status
 * @property ?Carbon $last_deploy_at
 * @property ?Carbon $suspended_at
 * @property string $git_repository_url
 * @property SiteType $type
 * @property string $env_file_content
 * @property string $deploy_strategy
 * @property-read ?Server $server
 */
trait TracksProvisioningStatus
{
    /**
     * Whether this site can export a full filesystem archive over SSH (BYO VM-style hosts only).
     */
    public function supportsSshFileArchive(): bool
    {
        if ($this->usesFunctionsRuntime()
            || $this->usesDockerRuntime()
            || $this->usesKubernetesRuntime()) {
            return false;
        }

        $server = $this->server;

        return $server !== null && $server->isReady();
    }

    /**
     * Whether worker mode is an explicit user choice (meta override) rather than
     * the host-role default. Lets the UI show the toggle in its real state.
     */
    public function workerModeIsExplicit(): bool
    {
        $meta = $this->meta ?? [];

        return array_key_exists('worker_mode', $meta) && $meta['worker_mode'] !== null;
    }

    /** @return array<string, mixed> */
    public function provisioningMeta(): array
    {
        $meta = $this->meta ?? [];
        $provisioning = $meta['provisioning'] ?? [];

        return is_array($provisioning) ? $provisioning : [];
    }

    /**
     * @return list<array{
     *     at?: string,
     *     level?: string,
     *     step?: string,
     *     message?: string,
     *     context?: array<string, mixed>
     * }>
     */
    public function provisioningLog(): array
    {
        $log = $this->provisioningMeta()['log'] ?? [];

        return collect(is_array($log) ? $log : [])
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->values()
            ->all();
    }

    public function provisioningState(): ?string
    {
        $state = $this->provisioningMeta()['state'] ?? null;

        return is_string($state) ? $state : null;
    }

    public function provisioningError(): ?string
    {
        $error = $this->provisioningMeta()['error'] ?? null;

        return is_string($error) ? $error : null;
    }

    public function provisionedHostname(): ?string
    {
        $hostname = $this->provisioningMeta()['ready_hostname'] ?? null;

        return is_string($hostname) && $hostname !== '' ? $hostname : null;
    }

    public function provisionedUrl(): ?string
    {
        $readyUrl = $this->provisioningMeta()['ready_url'] ?? null;
        if (is_string($readyUrl) && $readyUrl !== '') {
            // Stored ready_urls predate SSL provisioning and can be http:// even
            // though the host now serves https and forces a redirect. Upgrade the
            // scheme so visit/preview links hit the live https URL directly.
            $host = parse_url($readyUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== ''
                && str_starts_with($readyUrl, 'http://')
                && $this->urlSchemeForHostname($host) === 'https') {
                return 'https://'.substr($readyUrl, strlen('http://'));
            }

            return $readyUrl;
        }

        $hostname = $this->provisionedHostname();

        return $hostname ? $this->urlSchemeForHostname($hostname).'://'.$hostname : null;
    }

    public function isProvisioning(): bool
    {
        return $this->status === self::STATUS_PENDING
            && ! in_array($this->provisioningState(), ['ready', 'failed'], true);
    }

    public function isReadyForTraffic(): bool
    {
        return in_array($this->status, self::webserverActiveStatuses(), true)
            || in_array($this->status, [
                self::STATUS_DOCKER_ACTIVE,
                self::STATUS_KUBERNETES_ACTIVE,
                self::STATUS_FUNCTIONS_ACTIVE,
            ], true);
    }

    /**
     * @return list<string>
     */
    public static function webserverActiveStatuses(): array
    {
        return [
            self::STATUS_NGINX_ACTIVE,
            self::STATUS_APACHE_ACTIVE,
            self::STATUS_CADDY_ACTIVE,
            self::STATUS_OPENLITESPEED_ACTIVE,
            self::STATUS_TRAEFIK_ACTIVE,
        ];
    }

    public function isReadyForWorkspace(): bool
    {
        if ($this->isReadyForTraffic()) {
            return true;
        }

        // A site whose foundation provisioning completed ('ready') has a live,
        // navigable workspace even while an app install (scaffold) or redeploy is
        // in flight — the install is an operation INSIDE the site, not a reason
        // to un-render it and lock the operator out. (A brand-new, never-
        // provisioned scaffold has no 'ready' foundation yet, so it still gets
        // the full-page install journey until its shell exists.)
        if ($this->provisioningState() === 'ready') {
            return true;
        }

        if (in_array($this->status, [
            self::STATUS_DOCKER_CONFIGURED,
            self::STATUS_KUBERNETES_CONFIGURED,
            self::STATUS_FUNCTIONS_CONFIGURED,
            self::STATUS_CONTAINER_PROVISIONING,
            self::STATUS_CONTAINER_ACTIVE,
            self::STATUS_CONTAINER_FAILED,
            self::STATUS_CUSTOM_ACTIVE,
            self::STATUS_EDGE_ACTIVE,
        ], true)) {
            return true;
        }

        // Subsequent redeploys flip the site to STATUS_EDGE_PROVISIONING /
        // STATUS_EDGE_FAILED. Without this carve-out a failed-first-deploy
        // would land in the workspace showing a misleading "Open live site"
        // header (no site ever went live). Both transient + failed states
        // stay in the provisioning shell until a deploy actually publishes
        // — `active_deployment_id` is only set by PublishEdgeDeploymentJob
        // on a successful publish, so it's a reliable "has ever been live"
        // signal that persists across re-deploys.
        if (in_array($this->status, [self::STATUS_EDGE_PROVISIONING, self::STATUS_EDGE_FAILED], true)) {
            $activeDeploymentId = $this->edgeMeta()['active_deployment_id'] ?? null;
            if (is_string($activeDeploymentId) && $activeDeploymentId !== '') {
                return true;
            }
        }

        // Same idea for serverless: a failed first deploy must not open the
        // normal function workspace. `last_deploy_at` is only set on success,
        // so it's the "has ever been live" signal (redeploy failures on an
        // already-active function keep STATUS_FUNCTIONS_ACTIVE).
        if ($this->status === self::STATUS_FUNCTIONS_FAILED && $this->last_deploy_at !== null) {
            return true;
        }

        return false;
    }

    public function isCustom(): bool
    {
        return $this->type === SiteType::Custom;
    }

    /**
     * The app-install (scaffold) pipeline owns the pre-workspace surface for a
     * scaffolded site — a flow distinct from the bare-site provisioning journey.
     * True while a scaffold is running, has failed, or just completed but the
     * site shell isn't workspace-ready yet (the one-time password reveal window).
     * Once the shell is ready the normal workspace takes over.
     */
    public function isScaffoldJourneyActive(): bool
    {
        return is_array(data_get($this->meta, 'scaffold'))
            && ! $this->isReadyForWorkspace();
    }

    /**
     * An app install (scaffold) is running or just failed — regardless of
     * whether the site already has a live workspace. Used to surface the install
     * pipeline as a banner INSIDE an already-provisioned site's workspace (so the
     * install never takes the whole page over), distinct from
     * {@see isScaffoldJourneyActive()} which owns the pre-workspace surface for a
     * brand-new scaffold that has no shell yet.
     */
    public function isScaffoldInstalling(): bool
    {
        return is_array(data_get($this->meta, 'scaffold'))
            && in_array($this->status, [self::STATUS_SCAFFOLDING, self::STATUS_SCAFFOLD_FAILED], true);
    }

    public function isCustomGitMode(): bool
    {
        return $this->isCustom() && trim((string) $this->git_repository_url) !== '';
    }

    public function isCustomNoRepoMode(): bool
    {
        return $this->isCustom() && trim((string) $this->git_repository_url) === '';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_NGINX_ACTIVE => 'nginx active',
            self::STATUS_APACHE_ACTIVE => 'apache active',
            self::STATUS_CADDY_ACTIVE => 'caddy active',
            self::STATUS_OPENLITESPEED_ACTIVE => 'openlitespeed active',
            self::STATUS_TRAEFIK_ACTIVE => 'traefik active',
            self::STATUS_DOCKER_CONFIGURED => 'docker configured',
            self::STATUS_DOCKER_ACTIVE => 'docker active',
            self::STATUS_KUBERNETES_CONFIGURED => 'kubernetes configured',
            self::STATUS_KUBERNETES_ACTIVE => 'kubernetes active',
            self::STATUS_FUNCTIONS_CONFIGURED => 'functions configured',
            self::STATUS_FUNCTIONS_ACTIVE => 'functions active',
            self::STATUS_FUNCTIONS_FAILED => 'functions failed',
            self::STATUS_CUSTOM_ACTIVE => 'custom active',
            default => str_replace('_', ' ', $this->status),
        };
    }

    public static function activeStatusForWebserver(string $webserver): string
    {
        return match ($webserver) {
            'apache' => self::STATUS_APACHE_ACTIVE,
            'caddy' => self::STATUS_CADDY_ACTIVE,
            'openlitespeed' => self::STATUS_OPENLITESPEED_ACTIVE,
            'traefik' => self::STATUS_TRAEFIK_ACTIVE,
            'docker' => self::STATUS_DOCKER_ACTIVE,
            'kubernetes' => self::STATUS_KUBERNETES_ACTIVE,
            'digitalocean_functions' => self::STATUS_FUNCTIONS_ACTIVE,
            // Headless (no webserver) sites are "active" once code is deployed —
            // reuse the custom-active status, which the active-status lists and
            // labels already recognise.
            'none' => self::STATUS_CUSTOM_ACTIVE,
            default => self::STATUS_NGINX_ACTIVE,
        };
    }

    /**
     * Bare site waiting for the user to pick an application on
     * sites.choose-app. Distinct from STATUS_PENDING, which means "app
     * chosen, provisioning queued".
     */
    public function isAwaitingApp(): bool
    {
        return $this->status === self::STATUS_AWAITING_APP;
    }

    /**
     * True when the workspace should force the user onto the choose-app
     * picker before showing the normal site UI. Fires while the choose-app
     * flow is enabled for any site without an application installed yet —
     * both freshly-created bare sites (STATUS_AWAITING_APP) and pre-existing
     * web sites that never had a repo or deploy. A site the user explicitly
     * "skipped" is viewable and not forced back here — see
     * {@see canRechooseApp()}.
     */
    public function needsAppChoice(): bool
    {
        if (! config('dply.choose_app_enabled')) {
            return false;
        }

        if ((bool) data_get($this->meta, 'choose_app.skipped', false)) {
            return false;
        }

        return $this->isAwaitingApp() || $this->lacksInstalledApp();
    }

    /**
     * True when the choose-app picker should remain reachable for this site:
     * it is still bare, the user picked "Blank / Skip", or it is an existing
     * web site with no application installed. A successful real install
     * (git repo set, a deploy, or a scaffold) clears this.
     */
    public function canRechooseApp(): bool
    {
        // Once an application is actually installed (scaffolded, repo connected,
        // or deployed) there is nothing left to choose — never offer the picker,
        // even for services-first sites that still carry the "skipped" marker.
        // Resetting a site (disconnect & start over) clears those signals, which
        // flips lacksInstalledApp() back to true and re-opens the picker.
        if (! $this->lacksInstalledApp()) {
            return false;
        }

        // No app yet. A site already routed through the picker (awaiting an app,
        // or created services-first with "skipped") keeps its path even if the
        // feature flag is later turned off — otherwise the flag would strand
        // live, app-less sites with no way to attach a repo. The flag only gates
        // NEW entries (pre-existing web sites with no app installed).
        $alreadyInFlow = $this->isAwaitingApp()
            || (bool) data_get($this->meta, 'choose_app.skipped', false);

        return config('dply.choose_app_enabled') ? true : $alreadyInFlow;
    }

    /**
     * Lifecycle state of the post-repo-connect setup wizard (import/preset
     * sites), written by {@see PreflightSiteSetupJob} into
     * meta.setup.state: 'scanning' | 'deploying' | 'needs_setup' |
     * 'scan_failed'. Null once the site has deployed at least once, or for
     * sites that never entered the flow.
     */
    public function firstDeploySetupState(): ?string
    {
        if ($this->last_deploy_at !== null) {
            return null;
        }
        $state = data_get($this->meta, 'setup.state');

        return is_string($state) ? $state : null;
    }

    /** The pre-flight scan is still running; the wizard shows an "analyzing" state. */
    public function isPreflightScanning(): bool
    {
        return $this->firstDeploySetupState() === 'scanning';
    }

    /**
     * The pre-flight scan looks stuck: it's still in 'scanning' but its last
     * step heartbeat (meta.setup.scan_step_at) has gone cold past the given
     * threshold. The job may have died mid-scan (e.g. a worker crash) leaving
     * the wizard polling forever — the analyzing view surfaces a manual re-scan
     * once this trips so the operator can unstick it and proceed to deploy.
     */
    public function isPreflightStalled(int $seconds = 45): bool
    {
        if (! $this->isPreflightScanning()) {
            return false;
        }

        $at = data_get($this->meta, 'setup.scan_step_at')
            ?? data_get($this->meta, 'setup.started_at');

        // Scanning with no heartbeat at all → treat as stalled.
        if (! is_string($at) || trim($at) === '') {
            return true;
        }

        try {
            return Carbon::parse($at)->lt(now()->subSeconds($seconds));
        } catch (\Throwable) {
            return true;
        }
    }

    /** The pre-flight scan failed (auth/not-found/network); the wizard fails open with a fix-it banner. */
    public function setupScanFailed(): bool
    {
        return $this->firstDeploySetupState() === 'scan_failed';
    }

    public function setupScanFailureReason(): ?string
    {
        if (! $this->setupScanFailed()) {
            return null;
        }
        $reason = data_get($this->meta, 'setup.error');

        return is_string($reason) ? $reason : 'unknown';
    }

    /**
     * The site has connected a repo but is being held before its first deploy
     * pending setup (missing required env, or a failed scan to fix). Drives the
     * setup wizard and the Overview "finish setting up" card. The site stays
     * live (no status change) throughout — see {@see PreflightSiteSetupJob}.
     */
    public function needsFirstDeploySetup(): bool
    {
        return in_array($this->firstDeploySetupState(), ['needs_setup', 'scan_failed'], true);
    }

    /** Still scanning or actively held for setup — i.e. the setup wizard owns this site. */
    public function isInFirstDeploySetup(): bool
    {
        return in_array($this->firstDeploySetupState(), ['scanning', 'needs_setup', 'scan_failed'], true);
    }

    /**
     * True when this is a VM web site (PHP/Node) that has no application
     * installed yet: no git repository, never deployed, and not already
     * scaffolded or routed through the choose-app flow. Static, custom,
     * container, serverless and edge sites are excluded — they either don't
     * use a repo or have their own create flow. Sites mid-scaffold have
     * their own journey and are excluded too.
     */
    private function lacksInstalledApp(): bool
    {
        if (! in_array($this->type, [SiteType::Php, SiteType::Node], true)) {
            return false;
        }

        if ($this->usesFunctionsRuntime()
            || $this->usesDockerRuntime()
            || $this->usesKubernetesRuntime()
            || $this->usesContainerRuntime()
            || $this->usesEdgeRuntime()) {
            return false;
        }

        if (in_array($this->status, [self::STATUS_SCAFFOLDING, self::STATUS_SCAFFOLD_FAILED], true)) {
            return false;
        }

        // Already has an application by any of these signals.
        if (data_get($this->meta, 'scaffold.framework') !== null) {
            return false;
        }
        if (data_get($this->meta, 'choose_app.chosen_kind') !== null) {
            return false;
        }
        if (trim((string) $this->git_repository_url) !== '') {
            // The choose-app picker writes the URL mid-flow (via syncRepoUrlToSite)
            // so the ref picker can resolve branches before the form is submitted.
            // Don't count that as "installed" — require a completed choice or a
            // deploy. Legacy sites with no choose_app meta are pre-flow and their
            // URL is real evidence of an installed app.
            $midFlow = $this->isAwaitingApp()
                || data_get($this->meta, 'choose_app') !== null;
            if (! $midFlow) {
                return false;
            }
        }
        if ($this->last_deploy_at !== null) {
            return false;
        }

        return true;
    }

    public function isAtomicDeploys(): bool
    {
        return $this->deploy_strategy === 'atomic';
    }

    /**
     * Per-deploy ephemeral SSH credentials (org flag + site opt-in).
     */
    public function usesEphemeralDeployCredentials(): bool
    {
        return (bool) data_get($this->meta, 'deploy.ephemeral_credentials', false);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }
}
