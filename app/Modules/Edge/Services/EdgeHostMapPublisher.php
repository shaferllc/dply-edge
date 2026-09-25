<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeDeliveryContext;
use App\Modules\Edge\Support\EdgeEffectiveErrorPages;
use App\Modules\Edge\Support\EdgeEffectiveFirewall;
use App\Modules\Edge\Support\EdgeEffectiveImages;
use App\Modules\Edge\Support\EdgeEffectiveOrigin;
use App\Modules\Edge\Support\EdgeEffectiveRouting;
use App\Modules\Edge\Support\EdgeHostMapAddons;
use App\Modules\Edge\Support\FakeEdgeProvision;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class EdgeHostMapPublisher
{
    public function publish(Site $site, EdgeDeployment $deployment, ?EdgeDeliveryContext $context = null): int
    {
        $context ??= app(EdgeDeliveryContextResolver::class)->forSite($site);
        $version = (int) $deployment->cf_kv_version + 1;
        $this->publishHostname($site, $deployment, $site->edgeHostname(), $context, ! $site->isEdgePreview());

        foreach ($this->readyCustomHostnames($site) as $hostname) {
            $this->publishHostname($site, $deployment, $hostname, $context, true);
        }

        // Per-deploy stable aliases — generate (and persist) the first
        // time this deployment publishes, then re-emit on every
        // subsequent republish so the KV entries don't TTL out.
        $aliases = $deployment->aliasHostnames();
        if ($aliases === []) {
            $aliases = app(EdgeDeploymentAliasGenerator::class)->aliasesFor($site, $deployment);
            if ($aliases !== []) {
                $deployment->update(['aliases' => $aliases]);
            }
        }
        foreach ($aliases as $alias) {
            $this->publishHostname($site, $deployment, $alias, $context, false);
        }

        app(EdgeQueueConsumers::class)->sync($site, $deployment, $context);

        return $version;
    }

    public function publishHostname(
        Site $site,
        EdgeDeployment $deployment,
        string $hostname,
        ?EdgeDeliveryContext $context = null,
        bool $isProduction = false,
    ): void {
        $context ??= app(EdgeDeliveryContextResolver::class)->forSite($site);
        $payload = $this->routingPayload($deployment, $site, $isProduction);

        if (FakeEdgeProvision::enabled()) {
            $map = Cache::get('edge:fake:host-map', []);
            $map[strtolower($hostname)] = $payload;
            Cache::put('edge:fake:host-map', $map, now()->addDay());

            return;
        }

        $this->writeKv(strtolower($hostname), $payload, $context);
    }

    public function unpublish(Site $site, ?EdgeDeliveryContext $context = null): void
    {
        $context ??= app(EdgeDeliveryContextResolver::class)->forSite($site);
        $this->unpublishHostname($site, $site->edgeHostname(), $context);
        foreach ($this->readyCustomHostnames($site) as $hostname) {
            $this->unpublishHostname($site, $hostname, $context);
        }

        // Sweep alias hostnames from every deployment so torn-down sites
        // don't leave stale KV entries pointing at deleted R2 prefixes.
        foreach ($site->edgeDeployments as $deployment) {
            foreach ($deployment->aliasHostnames() as $alias) {
                $this->unpublishHostname($site, $alias, $context);
            }
        }
    }

    public function unpublishHostname(Site $site, string $hostname, ?EdgeDeliveryContext $context = null): void
    {
        $context ??= app(EdgeDeliveryContextResolver::class)->forSite($site);
        $hostname = strtolower(trim($hostname));

        if (FakeEdgeProvision::enabled()) {
            $map = Cache::get('edge:fake:host-map', []);
            unset($map[$hostname]);
            Cache::put('edge:fake:host-map', $map, now()->addDay());

            return;
        }

        Http::withToken($context->apiToken)
            ->delete($this->kvValueUrl($context, $hostname))
            ->throw();
    }

    /**
     * A plain KV flag the edge worker reads without a host-map republish.
     * Used to stop a free container from waking after the usage credit is gone.
     */
    public function putText(string $key, string $value, ?EdgeDeliveryContext $context = null): void
    {
        if (FakeEdgeProvision::enabled()) {
            $map = Cache::get('edge:fake:kv-text', []);
            $map[$key] = $value;
            Cache::put('edge:fake:kv-text', $map, now()->addDay());

            return;
        }

        if ($context === null || $context->kvNamespaceId === '' || $context->apiToken === '' || $context->accountId === '') {
            return;
        }

        Http::withToken($context->apiToken)
            ->withBody($value, 'text/plain')
            ->put($this->kvValueUrl($context, $key))
            ->throw();
    }

    public function deleteText(string $key, ?EdgeDeliveryContext $context = null): void
    {
        if (FakeEdgeProvision::enabled()) {
            $map = Cache::get('edge:fake:kv-text', []);
            unset($map[$key]);
            Cache::put('edge:fake:kv-text', $map, now()->addDay());

            return;
        }

        if ($context === null || $context->kvNamespaceId === '' || $context->apiToken === '' || $context->accountId === '') {
            return;
        }

        $response = Http::withToken($context->apiToken)->delete($this->kvValueUrl($context, $key));
        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeKv(string $key, array $payload, EdgeDeliveryContext $context): void
    {
        Http::withToken($context->apiToken)
            ->withBody(json_encode($payload, JSON_THROW_ON_ERROR), 'application/json')
            ->put($this->kvValueUrl($context, $key))
            ->throw();
    }

    private function kvValueUrl(EdgeDeliveryContext $context, string $key): string
    {
        return 'https://api.cloudflare.com/client/v4/accounts/'.$context->accountId
            .'/storage/kv/namespaces/'.$context->kvNamespaceId
            .'/values/'.rawurlencode($key);
    }

    /**
     * @return array<string, mixed>
     */
    private function routingPayload(EdgeDeployment $deployment, Site $site, bool $isProduction = false): array
    {
        $edgeMeta = $site->edgeMeta();
        $routing = is_array($edgeMeta['routing'] ?? null) ? $edgeMeta['routing'] : [];
        $isPreview = ! empty($edgeMeta['preview_parent_site_id']);
        $widgetMeta = is_array($edgeMeta['comment_widget'] ?? null) ? $edgeMeta['comment_widget'] : [];
        $widgetEnabled = $isPreview && (bool) ($widgetMeta['enabled'] ?? false);
        // Inherit widget config from the parent site so a single toggle
        // on the parent applies to every PR preview spawned from it.
        if ($isPreview && ! $widgetEnabled) {
            $parentId = $edgeMeta['preview_parent_site_id'] ?? null;
            if (is_string($parentId)) {
                $parent = Site::query()->find($parentId);
                $parentMeta = $parent?->edgeMeta() ?? [];
                $parentWidget = is_array($parentMeta['comment_widget'] ?? null) ? $parentMeta['comment_widget'] : [];
                if ((bool) ($parentWidget['enabled'] ?? false)) {
                    $widgetEnabled = true;
                    $widgetMeta = array_merge($parentWidget, $widgetMeta);
                }
            }
        }
        // Repo override: when dply.yaml `comment_widget.enabled: true`
        // is declared on the parent's most recent live deployment, the
        // widget activates on every preview without requiring the
        // dashboard toggle. Dashboard toggle still wins when set.
        if ($isPreview && ! $widgetEnabled) {
            $parentId = $edgeMeta['preview_parent_site_id'] ?? null;
            if (is_string($parentId)) {
                $parentLive = EdgeDeployment::query()
                    ->where('site_id', $parentId)
                    ->where('status', EdgeDeployment::STATUS_LIVE)
                    ->latest('id')
                    ->first();
                $repoCommentCfg = is_array($parentLive?->repo_config['comment_widget'] ?? null) ? $parentLive->repo_config['comment_widget'] : [];
                if ((bool) ($repoCommentCfg['enabled'] ?? false)) {
                    $widgetEnabled = true;
                    // Need a token to actually inject — pull whatever the
                    // parent has on edgeMeta (the dashboard generates it
                    // on first enable; surface it transparently here).
                    $parent = $parent ?? Site::query()->find($parentId);
                    $parentMeta = $parent?->edgeMeta() ?? [];
                    $parentWidget = is_array($parentMeta['comment_widget'] ?? null) ? $parentMeta['comment_widget'] : [];
                    $widgetMeta = array_merge($parentWidget, $widgetMeta);
                }
            }
        }

        $payload = [
            'storage_prefix' => $deployment->storage_prefix,
            'deployment_id' => $deployment->id,
            'site_id' => (string) $site->id,
            'organization_id' => (string) $site->organization_id,
            'spa_fallback' => (bool) ($routing['spa_fallback'] ?? true),
            'headers' => is_array($routing['headers'] ?? null) ? $routing['headers'] : [],
            'is_preview' => $isPreview,
            'is_production' => $isProduction,
            'comment_widget_enabled' => $widgetEnabled,
        ];

        $footerMeta = is_array($edgeMeta['deploy_footer'] ?? null) ? $edgeMeta['deploy_footer'] : [];
        if ((bool) ($footerMeta['enabled'] ?? false)) {
            $payload['deploy_footer'] = true;
        }

        // Skew protection (P51). Surface the last few SUPERSEDED
        // deploys' R2 prefixes so the Worker can fall back through
        // them when a hashed-asset URL 404s in the current prefix.
        // Old tabs that loaded HTML from deploy N keep working when
        // they request chunk files that only exist under deploy N's
        // storage_prefix. Pruner default keeps 10 superseded artifacts
        // alive — we expose the most recent 5 to keep KV payload small.
        $recentPrefixes = EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', EdgeDeployment::STATUS_SUPERSEDED)
            ->whereNotNull('storage_prefix')
            ->where('id', '!=', $deployment->id)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(5)
            ->pluck('storage_prefix')
            ->filter(fn ($p): bool => is_string($p) && $p !== '')
            ->values()
            ->all();
        if ($recentPrefixes !== []) {
            $payload['recent_storage_prefixes'] = $recentPrefixes;
        }

        // Protection covers the live hostname too. The Previews form warns
        // before save that visitors must pass the gate before the app loads.
        $accessGate = app(EdgeAccessGate::class)->kvPayloadForSite($site);
        if ($accessGate !== null) {
            $payload['access_gate'] = $accessGate;
        }

        // Routing rules — merge dply.yaml + dashboard overrides via
        // EdgeEffectiveRouting. Repo rules come first; dashboard
        // appends run after. The Worker applies redirects first
        // (308/301/etc.), then rewrites (to internal paths), then
        // layers matching header rules onto the final response.
        $effRouting = EdgeEffectiveRouting::for($site, $deployment);
        $stripSource = static fn (array $r): array => array_diff_key($r, ['source' => true]);
        if ($effRouting['redirects'] !== []) {
            $payload['repo_redirects'] = array_map($stripSource, $effRouting['redirects']);
        }
        if ($effRouting['rewrites'] !== []) {
            $payload['repo_rewrites'] = array_map($stripSource, $effRouting['rewrites']);
        }
        if ($effRouting['headers'] !== []) {
            $payload['repo_header_rules'] = array_map($stripSource, $effRouting['headers']);
        }

        if ($widgetEnabled) {
            $token = is_string($widgetMeta['token'] ?? null) ? trim((string) $widgetMeta['token']) : '';
            if ($token !== '') {
                $payload['comment_widget_token'] = $token;
            }
            $apiBase = rtrim((string) config('app.url'), '/');
            if ($apiBase !== '') {
                $payload['comment_widget_api_base'] = $apiBase;
            }
        }

        // Image optimization is independent of runtime mode — applies
        // to both static and hybrid sites. The Worker only enables the
        // /_dply/image route when a signing secret is present.
        // Allowed-hosts list merges repo (dply.yaml `images.allowed_hosts`)
        // with the dashboard list via EdgeEffectiveImages; the signing
        // secret stays dashboard-only (never round-trips to dply.yaml).
        $effImages = EdgeEffectiveImages::for($site, $deployment);
        if ($effImages['enabled']) {
            $payload['image_signing_secret'] = $effImages['signing_secret'];
            $payload['image_allowed_hosts'] = $effImages['allowed_hosts'];
        }

        // SSR: surface the per-deployment worker script name so the
        // platform Worker can dispatch to it instead of falling
        // through to R2/origin proxy logic. Script name lives on the
        // deployment meta (written by EdgeSsrBundleUploader).
        if (($edgeMeta['runtime_mode'] ?? 'static') === 'ssr') {
            $ssrMeta = is_array($deployment->meta['ssr'] ?? null) ? $deployment->meta['ssr'] : [];
            $scriptName = is_string($ssrMeta['script_name'] ?? null) ? trim($ssrMeta['script_name']) : '';
            if ($scriptName !== '') {
                $payload['runtime_mode'] = 'ssr';
                $payload['ssr_worker_script'] = $scriptName;
            }
        }

        // Container sites dispatch like SSR: the per-site script fronts the
        // app container (EdgeContainerDeployer). The Worker treats both the same.
        if (($edgeMeta['runtime_mode'] ?? 'static') === 'container') {
            $scriptName = trim((string) ($deployment->meta['container']['script_name'] ?? ''));
            if ($scriptName !== '') {
                $payload['runtime_mode'] = 'container';
                $payload['ssr_worker_script'] = $scriptName;
            }
        }

        // Middleware (P10a) — only for static + hybrid sites. SSR
        // sites bundle middleware via OpenNext so we'd double-run it.
        if (! in_array($edgeMeta['runtime_mode'] ?? 'static', ['ssr', 'container'], true)) {
            $mwMeta = is_array($deployment->meta['middleware'] ?? null) ? $deployment->meta['middleware'] : [];
            $mwScript = is_string($mwMeta['script_name'] ?? null) ? trim($mwMeta['script_name']) : '';
            if ($mwScript !== '') {
                $payload['middleware_worker_script'] = $mwScript;
            }
        }

        // Split traffic (P10d) — production-only, parent sites only.
        // The Worker reads `split` from KV, hashes the visitor cookie /
        // IP into a 0-99 bucket, and serves from preview_storage_prefix
        // for the configured percentage. Preview sites can't sub-split;
        // we always read split from the *parent's* meta.
        if (! $isPreview) {
            $split = is_array($edgeMeta['split'] ?? null) ? $edgeMeta['split'] : null;
            $percentage = is_array($split) ? (int) ($split['percentage'] ?? 0) : 0;
            $previewPrefix = is_array($split) ? (string) ($split['preview_storage_prefix'] ?? '') : '';
            if (is_array($split) && ($split['enabled'] ?? false) && $percentage > 0 && $previewPrefix !== '') {
                $payload['split'] = [
                    'preview_storage_prefix' => $previewPrefix,
                    'preview_deployment_id' => (string) ($split['preview_deployment_id'] ?? ''),
                    'percentage' => max(1, min(99, $percentage)),
                    'sticky_cookie' => is_string($split['sticky_cookie'] ?? null) ? $split['sticky_cookie'] : null,
                ];
            }
        }

        // Geo-block firewall (P55). Country list + allow/block mode
        // travel with the host map so the Worker rejects requests
        // before any other processing. Merges repo-declared rules
        // (dply.yaml `firewall:`) with dashboard overrides via
        // EdgeEffectiveFirewall.
        $effFirewall = EdgeEffectiveFirewall::for($site, $deployment);
        if ($effFirewall['country_mode'] !== 'off' && $effFirewall['countries'] !== []) {
            $payload['firewall_country_mode'] = $effFirewall['country_mode'];
            $payload['firewall_countries'] = $effFirewall['countries'];
        }

        // Custom error pages + maintenance (P52, P55-followup). Merges
        // repo-declared (dply.yaml) values with dashboard overrides via
        // EdgeEffectiveErrorPages — dashboard wins on conflict so an
        // operator can adjust during an incident without a redeploy.
        $effErrors = EdgeEffectiveErrorPages::for($site, $deployment);
        if (is_string($effErrors['html_404'])) {
            $payload['error_404_html'] = $effErrors['html_404'];
        }
        if (is_string($effErrors['html_500'])) {
            $payload['error_500_html'] = $effErrors['html_500'];
        }
        if (is_string($effErrors['maintenance_html'])) {
            $payload['maintenance_html'] = $effErrors['maintenance_html'];
        }
        if ($effErrors['maintenance_enabled']) {
            $payload['maintenance_mode'] = true;
        }

        if (($edgeMeta['runtime_mode'] ?? 'static') === 'hybrid') {
            // Hybrid origin (P55-followup): repo-declared `origin:` from
            // dply.yaml merges with dashboard origin meta via the
            // EdgeEffectiveOrigin helper. Dashboard wins for url +
            // failover_html (commonly env-specific); routes are unioned.
            $effOrigin = EdgeEffectiveOrigin::for($site, $deployment);
            $originUrl = $effOrigin['url'];
            if (is_string($originUrl) && $originUrl !== '') {
                $payload['origin_url'] = $originUrl;
                $payload['origin_routes'] = $effOrigin['routes'];
                if (is_string($effOrigin['auth_secret']) && $effOrigin['auth_secret'] !== '') {
                    $payload['origin_auth_secret'] = $effOrigin['auth_secret'];
                }
                if (is_string($effOrigin['failover_html']) && $effOrigin['failover_html'] !== '') {
                    $payload['origin_failover_html'] = $effOrigin['failover_html'];
                }
                // Cloudflare Access service token — lets the origin sit behind
                // Access (a Tunnel hostname, typically) instead of being open
                // to anyone who learns it. Both halves or neither.
                if (is_string($effOrigin['access_client_id']) && $effOrigin['access_client_id'] !== ''
                    && is_string($effOrigin['access_client_secret']) && $effOrigin['access_client_secret'] !== '') {
                    $payload['origin_access_client_id'] = $effOrigin['access_client_id'];
                    $payload['origin_access_client_secret'] = $effOrigin['access_client_secret'];
                }
            }
        }

        // Product add-ons (bot protection, rate limits, forms, waiting room,
        // snippets, tags, jobs) — managed dply_edge only. Tags/snippets/forms
        // merge dashboard edgeMeta over dply.yaml via EdgeEffectiveProductAddons.
        foreach (EdgeHostMapAddons::payload($site, $deployment) as $key => $value) {
            $payload[$key] = $value;
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function readyCustomHostnames(Site $site): array
    {
        $routing = is_array($site->edgeMeta()['routing'] ?? null) ? $site->edgeMeta()['routing'] : [];
        $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];
        $hosts = [];
        foreach ($domains as $hostname => $info) {
            if (! is_string($hostname) || $hostname === '') {
                continue;
            }
            if (is_array($info) && ($info['dns_status'] ?? null) !== 'ready') {
                continue;
            }
            $hosts[] = strtolower($hostname);
        }

        return $hosts;
    }
}
