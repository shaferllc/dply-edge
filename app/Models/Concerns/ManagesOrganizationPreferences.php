<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Site;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 *
 * @property string $icon_path
 * @property string $name
 * @property array<string, mixed> $server_site_preferences
 * @property array<string, mixed> $database_workspace_settings
 * @property array<string, mixed> $insights_preferences
 * @property array<string, mixed> $services_preferences
 * @property bool $deploy_email_notifications_enabled
 */
trait ManagesOrganizationPreferences
{
    /**
     * Public URL of the org's uploaded icon/logo, or null when none is set —
     * callers fall back to the generated initials avatar. Stored on the durable
     * `site_assets` disk so it survives a redeploy (mirrors {@see Site::logoUrl()}).
     */
    public function iconUrl(): ?string
    {
        $path = $this->icon_path;
        if (blank($path)) {
            return null;
        }

        return Storage::disk('site_assets')->url($path);
    }

    public function hasIcon(): bool
    {
        return filled($this->icon_path);
    }

    /**
     * 1–2 letter initials from the org name for the placeholder avatar shown
     * when no icon is uploaded.
     */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $words = array_values(array_filter($words, fn (string $w): bool => $w !== ''));

        if ($words === []) {
            return '?';
        }

        $first = Str::substr($words[0], 0, 1);
        $second = count($words) > 1 ? Str::substr($words[count($words) - 1], 0, 1) : '';

        return Str::upper($first.$second);
    }

    /**
     * @return array<string, mixed>
     */
    public function mergedServerSitePreferences(): array
    {
        $defaults = config('user_preferences.organization_server_site_defaults', []);
        $keys = array_keys($defaults);
        $stored = $this->server_site_preferences ?? [];

        return array_merge($defaults, array_intersect_key($stored, array_flip($keys)));
    }

    /**
     * Database workspace policy merged with config defaults (credential shares, import caps).
     *
     * @return array{credential_shares_enabled: bool, import_max_bytes: int|null}
     */
    public function mergedDatabaseWorkspaceSettings(): array
    {
        $defaults = config('server_database.organization_defaults', []);
        $keys = array_keys($defaults);
        $stored = $this->database_workspace_settings ?? [];

        return array_merge($defaults, array_intersect_key($stored, array_flip($keys)));
    }

    /**
     * @return array{digest_non_critical: bool, digest_frequency: string, quiet_hours_enabled: bool, quiet_hours_start: int, quiet_hours_end: int, allow_config_mutation?: bool}
     */
    public function mergedInsightsPreferences(): array
    {
        $defaults = config('insights.organization_defaults', []);
        $stored = $this->insights_preferences ?? [];

        return array_replace_recursive($defaults, $stored);
    }

    /**
     * @return array{
     *     deployer_systemd_actions_enabled: bool,
     *     systemd_notifications_digest: 'immediate'|'hourly',
     *     systemd_status_only_units: list<string>
     * }
     */
    public function mergedServicesPreferences(): array
    {
        $defaults = config('server_services.organization_defaults', []);
        $stored = $this->services_preferences ?? [];

        $merged = array_replace_recursive($defaults, $stored);
        $digest = (string) ($merged['systemd_notifications_digest'] ?? 'immediate');
        if (! in_array($digest, ['immediate', 'hourly'], true)) {
            $digest = 'immediate';
        }
        $norm = static function (mixed $v): string {
            if (! is_string($v)) {
                return '';
            }

            return strtolower(trim(str_replace('.service', '', $v)));
        };
        /** @var list<mixed> $globalOnly */
        $globalOnly = config('server_services.systemd_status_only_units', []);
        $fromGlobal = array_filter(array_map($norm, $globalOnly), static fn (string $v) => $v !== '');
        /** @var list<mixed> $statusOnly */
        $statusOnly = $merged['systemd_status_only_units'] ?? [];
        $fromOrg = array_filter(array_map($norm, $statusOnly), static fn (string $v) => $v !== '');
        $statusOnly = array_values(array_unique(array_merge($fromGlobal, $fromOrg)));

        return [
            'deployer_systemd_actions_enabled' => (bool) ($merged['deployer_systemd_actions_enabled'] ?? false),
            'systemd_notifications_digest' => $digest,
            'systemd_status_only_units' => $statusOnly,
        ];
    }

    public function allowsDatabaseCredentialShares(): bool
    {
        return (bool) $this->mergedDatabaseWorkspaceSettings()['credential_shares_enabled'];
    }

    /**
     * Effective SQL import size limit for this org (bytes), never above app import_max_bytes.
     */
    public function databaseImportMaxBytes(): int
    {
        $global = (int) config('server_database.import_max_bytes', 10485760);
        $raw = $this->mergedDatabaseWorkspaceSettings()['import_max_bytes'] ?? null;
        if ($raw === null) {
            return $global;
        }

        return min(max((int) $raw, 1024), $global);
    }

    /**
     * Whether deploy-related email (immediate or digest) should go to org stakeholders.
     * Global app config still disables all deploy notifications when off.
     */
    public function wantsDeployEmailNotifications(): bool
    {
        return (bool) $this->deploy_email_notifications_enabled;
    }
}
