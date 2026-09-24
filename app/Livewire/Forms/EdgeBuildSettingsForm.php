<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Models\EdgeSiteAccessRule;
use App\Models\Site;
use Livewire\Form;

class EdgeBuildSettingsForm extends Form
{
    public int $edge_releases_to_keep = 10;

    public string $edge_build_command = '';

    public string $edge_output_dir = 'dist';

    public bool $edge_spa_fallback = true;

    public bool $edge_deploy_on_push = true;

    public string $edge_repo_root = '';

    public string $edge_webhook_account_id = '';

    public string $edge_origin_url = '';

    public string $edge_origin_routes = '';

    public string $edge_origin_healthcheck_path = '/';

    public string $edge_origin_failover_html = '';

    public string $edge_origin_access_client_id = '';

    /**
     * Write-only. Hydrated blank so the stored secret is never echoed into the
     * page; a blank submit leaves the saved value alone.
     */
    public string $edge_origin_access_client_secret = '';

    public string $edge_convert_origin_url = '';

    public string $edge_cache_purge_tag = '';

    public string $edge_image_allowed_hosts = '';

    public bool $edge_image_optimization_enabled = false;

    public bool $edge_comment_widget_enabled = false;

    public bool $edge_deploy_footer_enabled = false;

    public string $edge_preview_protection_mode = EdgeSiteAccessRule::MODE_OFF;

    public string $edge_preview_protection_password = '';

    public string $edge_preview_protection_allowed_emails = '';

    public function syncFromSite(Site $site): void
    {
        $edge = $site->edgeMeta();
        $build = is_array($edge['build'] ?? null) ? $edge['build'] : [];
        $source = is_array($edge['source'] ?? null) ? $edge['source'] : [];
        $routing = is_array($edge['routing'] ?? null) ? $edge['routing'] : [];
        $origin = is_array($edge['origin'] ?? null) ? $edge['origin'] : [];

        $this->edge_build_command = (string) ($build['command'] ?? 'npm ci && npm run build');
        $this->edge_output_dir = (string) ($build['output_dir'] ?? 'dist');
        $this->edge_spa_fallback = (bool) ($routing['spa_fallback'] ?? ($edge['spa_fallback'] ?? true));
        $this->edge_deploy_on_push = (bool) ($source['deploy_on_push'] ?? true);
        $this->edge_repo_root = $site->edgeRepoRoot();

        $this->edge_origin_url = (string) ($origin['url'] ?? '');
        $originRoutes = is_array($origin['routes'] ?? null) ? $origin['routes'] : [];
        $this->edge_origin_routes = implode("\n", array_values(array_filter(array_map(
            fn ($route) => is_string($route) ? $route : null,
            $originRoutes,
        ))));
        $this->edge_origin_healthcheck_path = trim((string) ($origin['healthcheck_path'] ?? '/')) ?: '/';
        $this->edge_origin_failover_html = is_string($origin['failover_html'] ?? null) ? (string) $origin['failover_html'] : '';
        // Credentials come from the encrypted column, not meta. The secret
        // half stays blank — see the property docblock.
        $this->edge_origin_access_client_id = (string) ($site->edgeOriginSecret('access_client_id') ?? '');
        $this->edge_origin_access_client_secret = '';

        $images = is_array($edge['images'] ?? null) ? $edge['images'] : [];
        $this->edge_image_optimization_enabled = is_string($images['signing_secret'] ?? null) && $images['signing_secret'] !== '';
        $imageHosts = is_array($images['allowed_hosts'] ?? null) ? $images['allowed_hosts'] : [];
        $this->edge_image_allowed_hosts = implode("\n", array_values(array_filter(array_map(
            fn ($host) => is_string($host) ? $host : null,
            $imageHosts,
        ))));

        $widget = is_array($edge['comment_widget'] ?? null) ? $edge['comment_widget'] : [];
        $this->edge_comment_widget_enabled = (bool) ($widget['enabled'] ?? false);

        $footer = is_array($edge['deploy_footer'] ?? null) ? $edge['deploy_footer'] : [];
        $this->edge_deploy_footer_enabled = (bool) ($footer['enabled'] ?? false);

        $accessRule = $site->edgeSiteAccessRule;
        $this->edge_preview_protection_mode = is_string($accessRule?->mode)
            ? $accessRule->mode
            : EdgeSiteAccessRule::MODE_OFF;
        $this->edge_preview_protection_password = '';
        $this->edge_preview_protection_allowed_emails = implode("\n", $accessRule?->normalizedAllowedEmails() ?? []);

        $webhook = is_array($edge['webhook'] ?? null) ? $edge['webhook'] : null;
        $accountId = is_array($webhook) ? (string) ($webhook['account_id'] ?? '') : '';
        $this->edge_webhook_account_id = $accountId !== '' ? $accountId : '';

        $configured = (int) ($site->releases_to_keep ?? 0);
        $this->edge_releases_to_keep = $configured > 0
            ? $configured
            : (int) config('edge.retention.default_keep', 10);

        $this->edge_convert_origin_url = '';
        $this->edge_cache_purge_tag = '';
    }
}
