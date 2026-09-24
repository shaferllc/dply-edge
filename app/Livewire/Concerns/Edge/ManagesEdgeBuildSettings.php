<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Edge;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Forms\EdgeBuildSettingsForm;
use App\Models\EdgeDeployHook;
use App\Models\EdgeDeployment;
use App\Models\EdgeSiteAccessRule;
use App\Models\EdgeSiteEnvVar;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeAccessGate;
use App\Modules\Edge\Services\EdgeCachePurger;
use App\Modules\Edge\Services\EdgeGithubWebhookProvisioner;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use App\Modules\Edge\Support\EdgeRepoRoot;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Rules\PubliclyRoutableUrl;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 *
 * @property Site $site
 * @property EdgeBuildSettingsForm $buildForm
 */
trait ManagesEdgeBuildSettings
{
    use DispatchesToastNotifications;

    /**
     * Form input for minting a new deploy hook (P10b). Cleared after
     * a successful mint; the resulting plaintext URL surfaces via
     * {@see $edge_just_minted_deploy_hook_url} once.
     */
    public string $edge_new_deploy_hook_name = '';

    /**
     * Plaintext hook URL shown to the operator exactly once right
     * after mint. Persisted-only-in-component-state so a page reload
     * makes it disappear (matching API-token UX).
     */
    public ?string $edge_just_minted_deploy_hook_url = null;

    public string $edge_env_var_key = '';

    public string $edge_env_var_value = '';

    public function mountEdgeBuildSettings(Site $site): void
    {
        $this->buildForm->syncFromSite($site);
    }

    public function saveEdgeReleasesToKeep(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $value = (int) $this->buildForm->edge_releases_to_keep;
        if ($value < 1 || $value > 50) {
            $this->toastError(__('Releases to keep must be between 1 and 50.'));

            return;
        }

        $this->site->update(['releases_to_keep' => $value]);
        $this->site->refresh();

        $this->toastSuccess(__('Retention updated.'));
    }

    public function saveEdgeBuildSettings(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $this->validate([
            'buildForm.edge_build_command' => ['required', 'string', 'max:500'],
            'buildForm.edge_output_dir' => ['required', 'string', 'max:200'],
            'buildForm.edge_spa_fallback' => ['boolean'],
            'buildForm.edge_deploy_on_push' => ['boolean'],
            'buildForm.edge_repo_root' => ['nullable', 'string', 'max:255'],
        ]);

        $site = $this->site->fresh();
        $edge = $site->edgeMeta();
        $build = is_array($edge['build'] ?? null) ? $edge['build'] : [];
        $source = is_array($edge['source'] ?? null) ? $edge['source'] : [];
        $routing = is_array($edge['routing'] ?? null) ? $edge['routing'] : [];

        $previousSpaFallback = (bool) ($routing['spa_fallback'] ?? ($edge['spa_fallback'] ?? true));

        $build['command'] = trim($this->buildForm->edge_build_command);
        $build['output_dir'] = trim($this->buildForm->edge_output_dir);
        $source['deploy_on_push'] = $this->buildForm->edge_deploy_on_push;
        $normalizedRepoRoot = EdgeRepoRoot::normalize($this->buildForm->edge_repo_root);
        if ($normalizedRepoRoot !== '') {
            $source['repo_root'] = $normalizedRepoRoot;
        } else {
            unset($source['repo_root']);
        }
        $routing['spa_fallback'] = $this->buildForm->edge_spa_fallback;

        $site->mergeEdgeMeta([
            'build' => $build,
            'source' => $source,
            'routing' => $routing,
        ]);
        $site->save();
        $this->site->refresh();

        if ($previousSpaFallback !== $this->buildForm->edge_spa_fallback) {
            $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
            if (is_string($activeId) && $activeId !== '') {
                $deployment = EdgeDeployment::query()->find($activeId);
                if ($deployment !== null && $deployment->status === EdgeDeployment::STATUS_LIVE) {
                    app(EdgeHostMapPublisher::class)->publish($site->fresh(), $deployment);
                }
            }
        }

        $this->toastSuccess(__('Build settings saved. Changes apply on the next deploy.'));
    }

    public function saveEdgeHybridOrigin(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $edge = $this->site->edgeMeta();
        if (($edge['runtime_mode'] ?? 'static') !== 'hybrid') {
            $this->toastError(__('Origin settings only apply to hybrid Edge sites.'));

            return;
        }

        $this->validate([
            'buildForm.edge_origin_url' => ['required', 'string', 'max:500', 'url:http,https', new PubliclyRoutableUrl],
            'buildForm.edge_origin_routes' => ['required', 'string', 'max:2000'],
            'buildForm.edge_origin_healthcheck_path' => ['required', 'string', 'max:200', 'regex:#^/[^\s]*$#'],
            'buildForm.edge_origin_failover_html' => ['nullable', 'string', 'max:32768'],
            'buildForm.edge_origin_access_client_id' => ['nullable', 'string', 'max:200'],
            'buildForm.edge_origin_access_client_secret' => ['nullable', 'string', 'max:200'],
        ], [
            'buildForm.edge_origin_url.url' => __('Origin URL must be a valid http(s) URL.'),
            'buildForm.edge_origin_healthcheck_path.regex' => __('Healthcheck path must start with / and contain no spaces.'),
            'buildForm.edge_origin_failover_html.max' => __('Failover HTML must be 32 KB or smaller.'),
        ]);

        $routes = array_values(array_filter(array_map(
            fn (string $line): string => trim($line),
            preg_split('/\R/', $this->buildForm->edge_origin_routes) ?: [],
        )));
        if ($routes === []) {
            $this->addError('buildForm.edge_origin_routes', __('Add at least one proxy route (e.g. /api/*).'));

            return;
        }
        foreach ($routes as $route) {
            if ($route[0] !== '/' || preg_match('/\s/', $route) === 1) {
                $this->addError(
                    'buildForm.edge_origin_routes',
                    __('Route ":route" is invalid — must start with / and contain no spaces.', ['route' => $route]),
                );

                return;
            }
        }

        $site = $this->site->fresh();
        $edge = $site->edgeMeta();
        $previousOrigin = is_array($edge['origin'] ?? null) ? $edge['origin'] : [];

        $failoverHtml = trim($this->buildForm->edge_origin_failover_html);
        $newOrigin = [
            'url' => trim($this->buildForm->edge_origin_url),
            'cloud_site_id' => $previousOrigin['cloud_site_id'] ?? null,
            'managed' => (bool) ($previousOrigin['managed'] ?? false),
            'routes' => $routes,
            'healthcheck_path' => trim($this->buildForm->edge_origin_healthcheck_path) ?: '/',
            'failover_html' => $failoverHtml !== '' ? $failoverHtml : null,
        ];

        // Credentials live in the encrypted `edge_origin_secrets` column, not
        // in meta. Mint the shared secret on first save so a hybrid origin is
        // never published without auth.
        $secretPatch = [];
        if ($site->edgeOriginSecret('auth_secret') === null) {
            $secretPatch['auth_secret'] = Str::random(48);
        }

        // Access client id: a blank submit clears it (both halves go together).
        // Access client secret: a blank submit keeps whatever is stored, since
        // the field is never populated with the saved value.
        $accessId = trim($this->buildForm->edge_origin_access_client_id);
        $accessSecret = trim($this->buildForm->edge_origin_access_client_secret);
        $secretPatch['access_client_id'] = $accessId !== '' ? $accessId : null;
        if ($accessId === '') {
            $secretPatch['access_client_secret'] = null;
        } elseif ($accessSecret !== '') {
            $secretPatch['access_client_secret'] = $accessSecret;
        }

        $previousSecrets = $site->edge_origin_secrets ?? [];
        $site->mergeEdgeOriginSecrets($secretPatch);
        $secretsChanged = ($site->edge_origin_secrets ?? []) !== $previousSecrets;
        if ($secretsChanged) {
            $site->save();
            $this->buildForm->edge_origin_access_client_secret = '';
        }

        // Credentials are published in the host map, so a token-only change
        // still has to fall through and republish — not short-circuit here.
        if ($newOrigin === $previousOrigin && ! $secretsChanged) {
            $this->toastSuccess(__('Origin settings unchanged.'));

            return;
        }

        $site->mergeEdgeMeta(['origin' => $newOrigin]);
        $site->save();
        $this->site->refresh();

        $org = $site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.origin.updated', $site, [
                'origin' => $previousOrigin,
            ], [
                'origin' => $newOrigin,
            ]);
        }

        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (is_string($activeId) && $activeId !== '') {
            $deployment = EdgeDeployment::query()->find($activeId);
            if ($deployment !== null && $deployment->status === EdgeDeployment::STATUS_LIVE) {
                app(EdgeHostMapPublisher::class)->publish($site->fresh(), $deployment);
            }
        }

        $this->toastSuccess(__('Origin settings saved. Worker host map updated.'));
    }

    public function saveEdgeImageOptimization(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $this->validate([
            'buildForm.edge_image_allowed_hosts' => ['required_if:buildForm.edge_image_optimization_enabled,true', 'nullable', 'string', 'max:2000'],
        ]);

        $site = $this->site->fresh();
        $edge = $site->edgeMeta();
        $previousImages = is_array($edge['images'] ?? null) ? $edge['images'] : [];

        if (! $this->buildForm->edge_image_optimization_enabled) {
            $site->mergeEdgeMeta(['images' => []]);
            $site->save();
            $this->site->refresh();

            $org = $site->organization;
            if ($org !== null) {
                audit_log($org, auth()->user(), 'site.edge.images.disabled', $site, [
                    'images' => $previousImages,
                ], null);
            }

            $this->republishHostMapForActiveDeployment();
            $this->toastSuccess(__('Image optimization disabled.'));

            return;
        }

        $hosts = $this->parseEdgeAllowedHosts($this->buildForm->edge_image_allowed_hosts);
        if ($hosts === []) {
            $this->addError('buildForm.edge_image_allowed_hosts', __('Add at least one source hostname.'));

            return;
        }
        foreach ($hosts as $host) {
            if (preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
                $this->addError('buildForm.edge_image_allowed_hosts', __('Hostname ":h" looks invalid — letters, digits, dot, dash only.', ['h' => $host]));

                return;
            }
        }

        $newImages = [
            'signing_secret' => is_string($previousImages['signing_secret'] ?? null) && $previousImages['signing_secret'] !== ''
                ? $previousImages['signing_secret']
                : Str::random(48),
            'allowed_hosts' => $hosts,
        ];

        $site->mergeEdgeMeta(['images' => $newImages]);
        $site->save();
        $this->site->refresh();

        $org = $site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.images.saved', $site, [
                'images' => $previousImages,
            ], [
                'images' => $newImages,
            ]);
        }

        $this->republishHostMapForActiveDeployment();

        $this->toastSuccess(__('Image optimization settings saved. Worker host map updated.'));
    }

    public function rotateEdgeImageSigningSecret(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $site = $this->site->fresh();
        $edge = $site->edgeMeta();
        $previousImages = is_array($edge['images'] ?? null) ? $edge['images'] : [];
        if (($previousImages['signing_secret'] ?? '') === '') {
            $this->toastError(__('Enable image optimization first.'));

            return;
        }

        $newImages = $previousImages;
        $newImages['signing_secret'] = Str::random(48);

        $site->mergeEdgeMeta(['images' => $newImages]);
        $site->save();
        $this->site->refresh();

        $org = $site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.images.secret_rotated', $site);
        }

        $this->republishHostMapForActiveDeployment();

        $this->toastSuccess(__('Image signing secret rotated. Re-sign any pre-rendered URLs.'));
    }

    public function saveEdgeCommentWidget(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $site = $this->site->fresh();
        $edge = $site->edgeMeta();
        $previous = is_array($edge['comment_widget'] ?? null) ? $edge['comment_widget'] : [];

        if (! $this->buildForm->edge_comment_widget_enabled) {
            $site->mergeEdgeMeta(['comment_widget' => []]);
            $site->save();
            $this->site->refresh();

            $org = $site->organization;
            if ($org !== null) {
                audit_log($org, auth()->user(), 'site.edge.comment_widget.disabled', $site, [
                    'comment_widget' => $previous,
                ], null);
            }

            $this->republishHostMapForActiveDeployment();

            $this->toastSuccess(__('Preview comment widget disabled.'));

            return;
        }

        $newWidget = [
            'enabled' => true,
            'token' => is_string($previous['token'] ?? null) && $previous['token'] !== ''
                ? $previous['token']
                : Str::random(48),
        ];

        $site->mergeEdgeMeta(['comment_widget' => $newWidget]);
        $site->save();
        $this->site->refresh();

        $org = $site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.comment_widget.enabled', $site, [
                'comment_widget' => $previous,
            ], [
                'comment_widget' => $newWidget,
            ]);
        }

        $this->republishHostMapForActiveDeployment();

        $this->toastSuccess(__('Preview comment widget enabled. New previews ship with the widget script injected.'));
    }

    public function saveEdgeDeployFooter(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $site = $this->site->fresh();
        $enabled = $this->buildForm->edge_deploy_footer_enabled;
        $site->mergeEdgeMeta([
            'deploy_footer' => $enabled ? ['enabled' => true] : [],
        ]);
        $site->save();
        $this->site->refresh();

        $this->republishHostMapForActiveDeployment();

        $this->toastSuccess($enabled
            ? __('Deploy id will show in the site footer.')
            : __('Deploy id removed from the site footer.'));
    }

    public function saveEdgePreviewProtection(): void
    {
        if (! $this->site->usesEdgeRuntime() || $this->site->isEdgePreview()) {
            return;
        }
        $this->authorize('update', $this->site);

        $this->validate([
            'buildForm.edge_preview_protection_mode' => ['required', 'in:off,password,dply_account'],
            'buildForm.edge_preview_protection_password' => ['nullable', 'string', 'max:200'],
            'buildForm.edge_preview_protection_allowed_emails' => ['nullable', 'string', 'max:5000'],
        ]);

        $emails = $this->parseEdgePreviewAllowedEmails($this->buildForm->edge_preview_protection_allowed_emails);
        $password = trim($this->buildForm->edge_preview_protection_password);
        $site = $this->site->fresh();
        $previous = $site->edgeSiteAccessRule?->only(['mode', 'allowed_emails']) ?? ['mode' => EdgeSiteAccessRule::MODE_OFF];

        app(EdgeAccessGate::class)->sync(
            $site,
            $this->buildForm->edge_preview_protection_mode,
            $password !== '' ? $password : null,
            $emails,
        );

        $this->buildForm->edge_preview_protection_password = '';
        $this->site->refresh();
        $this->buildForm->edge_preview_protection_allowed_emails = implode(
            "\n",
            $this->site->edgeSiteAccessRule?->normalizedAllowedEmails() ?? [],
        );

        $org = $site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.preview_protection.updated', $site, [
                'access_rule' => $previous,
            ], [
                'access_rule' => $this->site->edgeSiteAccessRule?->only(['mode', 'allowed_emails']),
            ]);
        }

        $this->toastSuccess(__('Preview protection updated.'));
    }

    /** @return list<string> */
    private function parseEdgePreviewAllowedEmails(string $raw): array
    {
        $parts = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            foreach (array_map('trim', explode(',', $line)) as $email) {
                $parts[] = strtolower($email);
            }
        }

        return array_values(array_unique(array_filter(
            $parts,
            static fn (string $email): bool => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }

    /** @return list<string> */
    private function parseEdgeAllowedHosts(string $raw): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $line): string => strtolower(trim($line)),
            preg_split('/\R/', $raw) ?: [],
        ), fn (string $line): bool => $line !== '')));
    }

    private function republishHostMapForActiveDeployment(): void
    {
        $activeId = $this->site->edgeMeta()['active_deployment_id'] ?? null;
        if (! is_string($activeId) || $activeId === '') {
            return;
        }
        $deployment = EdgeDeployment::query()->find($activeId);
        if ($deployment === null || $deployment->status !== EdgeDeployment::STATUS_LIVE) {
            return;
        }
        app(EdgeHostMapPublisher::class)->publish($this->site->fresh(), $deployment);
    }

    public function purgeEdgeCacheByTag(EdgeCachePurger $purger): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $tag = trim($this->buildForm->edge_cache_purge_tag);
        if ($tag === '') {
            $this->addError('buildForm.edge_cache_purge_tag', __('Enter a tag to purge.'));

            return;
        }

        $result = $purger->purgeByTag($this->site->fresh(), $tag);

        $org = $this->site->organization;
        if ($result['ok'] && $org !== null && $result['purged_keys'] !== []) {
            audit_log($org, auth()->user(), 'site.edge.cache.purged_by_tag', $this->site, null, [
                'tag' => $tag,
                'purged_keys' => $result['purged_keys'],
            ]);
        }

        if ($result['ok']) {

            $this->toastSuccess($result['message']);

        } else {

            $this->toastError($result['message']);

        }

        if ($result['ok']) {
            $this->buildForm->edge_cache_purge_tag = '';
        }
    }

    public function convertEdgeStaticToHybrid(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $edge = $this->site->edgeMeta();
        if (($edge['runtime_mode'] ?? 'static') === 'hybrid') {
            $this->toastError(__('Site is already in hybrid mode.'));

            return;
        }

        $this->validate([
            'buildForm.edge_convert_origin_url' => ['required', 'string', 'max:500', 'url:http,https'],
        ], [
            'buildForm.edge_convert_origin_url.url' => __('Origin URL must be a valid http(s) URL.'),
        ]);

        $site = $this->site->fresh();
        $site->mergeEdgeMeta([
            'runtime_mode' => 'hybrid',
            'origin' => [
                'url' => trim($this->buildForm->edge_convert_origin_url),
                'cloud_site_id' => null,
                'managed' => false,
                'routes' => ['/api/*', '/_next/data/*'],
                'healthcheck_path' => '/',
                'failover_html' => null,
            ],
        ]);
        $site->mergeEdgeOriginSecrets(['auth_secret' => Str::random(48)]);
        $site->save();
        $this->site->refresh();

        $org = $site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.converted_to_hybrid', $site, null, [
                'origin_url' => trim($this->buildForm->edge_convert_origin_url),
            ]);
        }

        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (is_string($activeId) && $activeId !== '') {
            $deployment = EdgeDeployment::query()->find($activeId);
            if ($deployment !== null && $deployment->status === EdgeDeployment::STATUS_LIVE) {
                app(EdgeHostMapPublisher::class)->publish($site->fresh(), $deployment);
            }
        }

        $this->buildForm->edge_convert_origin_url = '';
        $this->mountEdgeBuildSettings($this->site);

        $this->toastSuccess(__('Site converted to hybrid. Worker host map updated.'));
    }

    public function rotateEdgeHybridOriginSecret(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $edge = $this->site->edgeMeta();
        if (($edge['runtime_mode'] ?? 'static') !== 'hybrid') {
            $this->toastError(__('Origin secret rotation only applies to hybrid Edge sites.'));

            return;
        }

        $site = $this->site->fresh();
        $edge = $site->edgeMeta();
        $previousOrigin = is_array($edge['origin'] ?? null) ? $edge['origin'] : [];
        if (($previousOrigin['url'] ?? '') === '') {
            $this->toastError(__('Set the origin URL first.'));

            return;
        }

        $site->mergeEdgeOriginSecrets(['auth_secret' => Str::random(48)]);
        $site->save();
        $this->site->refresh();

        $org = $site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.origin.secret_rotated', $site);
        }

        $activeId = $site->edgeMeta()['active_deployment_id'] ?? null;
        if (is_string($activeId) && $activeId !== '') {
            $deployment = EdgeDeployment::query()->find($activeId);
            if ($deployment !== null && $deployment->status === EdgeDeployment::STATUS_LIVE) {
                app(EdgeHostMapPublisher::class)->publish($site->fresh(), $deployment);
            }
        }

        $this->toastSuccess(__('Origin auth secret rotated. Update your origin app and redeploy if needed.'));
    }

    public function enableEdgeGithubWebhook(EdgeGithubWebhookProvisioner $provisioner): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        if ($this->buildForm->edge_webhook_account_id === '') {
            $this->toastError(__('Select a linked GitHub account first.'));

            return;
        }

        $account = auth()->user() !== null
            ? app(GitIdentityResolver::class)->forId(auth()->user(), $this->buildForm->edge_webhook_account_id)
            : null;
        if ($account === null) {
            $this->toastError(__('That GitHub account is no longer linked.'));

            return;
        }

        $result = $provisioner->enable($this->site->fresh(), $account);
        if (! $result['ok']) {
            $this->toastError($result['message']);

            return;
        }

        $this->site->refresh();
        $this->toastSuccess($result['message']);
    }

    public function disableEdgeGithubWebhook(EdgeGithubWebhookProvisioner $provisioner): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $account = null;
        if ($this->buildForm->edge_webhook_account_id !== '' && auth()->user() !== null) {
            $account = app(GitIdentityResolver::class)->forId(auth()->user(), $this->buildForm->edge_webhook_account_id);
        }

        $provisioner->disable($this->site->fresh(), $account);
        $this->site->refresh();

        $this->toastSuccess(__('GitHub webhook disconnected.'));
    }

    public function mintEdgeDeployHook(): void
    {
        if (! $this->site->usesEdgeRuntime() || $this->site->isEdgePreview()) {
            return;
        }
        $this->authorize('update', $this->site);

        $name = trim($this->edge_new_deploy_hook_name);
        if ($name === '') {
            $this->toastError(__('Give the hook a name so you can tell yours apart later.'));

            return;
        }

        $result = EdgeDeployHook::mintFor($this->site, $name, (string) auth()->id());
        $this->edge_new_deploy_hook_name = '';
        $this->edge_just_minted_deploy_hook_url = $result['hook_url'];

        $org = $this->site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.deploy_hook.created', $this->site, null, [
                'hook_id' => $result['hook']->id,
                'name' => $name,
            ]);
        }

        $this->toastSuccess(__('Deploy hook created. Copy the URL now — dply won\'t show it again.'));
    }

    public function dismissEdgeDeployHookUrl(): void
    {
        $this->edge_just_minted_deploy_hook_url = null;
    }

    public function revokeEdgeDeployHook(string $hookId): void
    {
        if (! $this->site->usesEdgeRuntime() || $this->site->isEdgePreview()) {
            return;
        }
        $this->authorize('update', $this->site);

        $hook = EdgeDeployHook::query()
            ->where('site_id', $this->site->id)
            ->find($hookId);

        if ($hook === null) {
            return;
        }

        $name = $hook->name;
        $hook->delete();

        $org = $this->site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.deploy_hook.revoked', $this->site, [
                'hook_id' => $hookId,
                'name' => $name,
            ], null);
        }

        $this->toastSuccess(__('Deploy hook revoked. The URL will return 404 immediately.'));
    }

    /**
     * @return Collection<int, EdgeDeployHook>
     */
    public function edgeDeployHooks(): Collection
    {
        return EdgeDeployHook::query()
            ->where('site_id', $this->site->id)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @return list<array{key: string, updated_at: ?string}>
     */
    public function edgeEnvVarKeys(): array
    {
        if (! $this->site->usesEdgeRuntime()) {
            return [];
        }

        return $this->site->edgeEnvVars()
            ->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)
            ->get(['key', 'updated_at'])
            ->map(fn (EdgeSiteEnvVar $v): array => [
                'key' => (string) $v->key,
                'updated_at' => $v->updated_at?->diffForHumans(),
            ])
            ->all();
    }

    public function saveEdgeEnvVar(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $key = strtoupper(trim($this->edge_env_var_key));
        $value = (string) $this->edge_env_var_value;

        $reason = EdgeSiteEnvVar::rejectionReason($key);
        if ($reason !== null) {
            $this->addError('edge_env_var_key', $reason);

            return;
        }

        $existing = $this->site->edgeEnvVars()
            ->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)
            ->where('key', $key)
            ->first();

        $action = $existing === null ? 'site.edge.env.set' : 'site.edge.env.updated';

        if ($existing === null) {
            (new EdgeSiteEnvVar([
                'site_id' => $this->site->id,
                'key' => $key,
                'value' => $value,
                'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION,
                'created_by_user_id' => auth()->id(),
            ]))->save();
        } else {
            $existing->value = $value;
            $existing->created_by_user_id = auth()->id() ?? $existing->created_by_user_id;
            $existing->save();
        }

        if ($this->site->organization !== null) {
            audit_log(
                $this->site->organization,
                auth()->user(),
                $action,
                $this->site,
                null,
                ['key' => $key],
            );
        }

        $this->edge_env_var_key = '';
        $this->edge_env_var_value = '';
        $this->resetErrorBag(['edge_env_var_key', 'edge_env_var_value']);

        $this->toastSuccess(__('Env var saved. Redeploy to apply.'));
    }

    public function removeEdgeEnvVar(string $key): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $deleted = $this->site->edgeEnvVars()
            ->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)
            ->where('key', $key)
            ->delete();

        if ($deleted > 0 && $this->site->organization !== null) {
            audit_log(
                $this->site->organization,
                auth()->user(),
                'site.edge.env.removed',
                $this->site,
                ['key' => $key],
                null,
            );
        }

        $this->toastSuccess(__('Env var removed.'));
    }
}
