<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\ManagesEdgeBuildSettings;
use App\Livewire\Concerns\Edge\ManagesEdgeRedeploy;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Forms\EdgeBuildSettingsForm;
use App\Livewire\Sites\Concerns\ManagesLinkedOrganizationSecrets;
use App\Models\EdgeDeployment;
use App\Models\EdgeSiteEnvVar;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Services\Sites\DotEnvFileParser;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class Environment extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesEdgeBuildSettings;
    use ManagesEdgeRedeploy;
    use ManagesLinkedOrganizationSecrets;
    use MountsEdgeWorkspaceSection;

    public EdgeBuildSettingsForm $buildForm;

    public string $edgeEnvText = '';

    public bool $pending = false;

    /**
     * Every env row for the site, loaded once per request. Private, so it is
     * never serialized into the snapshot; values decrypt only when read.
     *
     * @var Collection<int, EdgeSiteEnvVar>|null
     */
    private ?Collection $envRows = null;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->mountEdgeBuildSettings($site);
        if (auth()->user()?->can('update', $site)) {
            $this->edgeEnvText = $this->envText();
        }
    }

    public function saveEdgeEnvText(bool $quiet = false): bool
    {
        $this->authorize('update', $this->site);
        $parsed = app(DotEnvFileParser::class)->parse($this->edgeEnvText);
        if ($parsed['errors'] !== []) {
            $this->addError('edgeEnvText', $parsed['errors'][0]);

            return false;
        }

        $managed = array_flip(EdgeContainerConnections::MANAGED_REDIS_KEYS);
        $pairs = [];
        foreach ($parsed['variables'] as $rawKey => $value) {
            $key = strtoupper((string) $rawKey);
            if (isset($managed[$key])) {
                continue;
            }
            $reason = EdgeSiteEnvVar::rejectionReason($key);
            if ($reason !== null) {
                $this->addError('edgeEnvText', $reason);

                return false;
            }
            $pairs[$key] = (string) $value;
        }

        $existing = $this->site->edgeEnvVars()
            ->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)
            ->get()
            ->keyBy('key');

        foreach ($pairs as $key => $value) {
            $row = $existing->get($key);
            if ($row === null) {
                (new EdgeSiteEnvVar([
                    'site_id' => $this->site->id,
                    'key' => $key,
                    'value' => $value,
                    'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION,
                    'created_by_user_id' => auth()->id(),
                ]))->save();
            } elseif ($row->value !== $value) {
                $row->value = $value;
                $row->save();
            }
        }

        $removed = $existing->keys()->diff(array_keys($pairs))->reject(
            static fn (string $key): bool => isset($managed[$key]),
        );
        if ($removed->isNotEmpty()) {
            $this->site->edgeEnvVars()
                ->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)
                ->whereIn('key', $removed->all())
                ->delete();
        }

        $this->resetErrorBag('edgeEnvText');
        $this->envRows = null;
        $this->edgeEnvText = $this->envText();
        $this->pending = false;
        $this->dispatch('close-modal', 'env-edit');
        if (! $quiet) {
            $this->toastSuccess(__('Saved. Redeploy to apply these settings.'));
        }

        return true;
    }

    /** Open the editor with an empty line for a key dply.yaml declares but has no value. */
    public function openEnvForKey(string $key): void
    {
        $this->authorize('update', $this->site);
        $key = strtoupper(trim($key));
        if ($key !== '' && preg_match('/^'.preg_quote($key, '/').'=/m', $this->edgeEnvText) !== 1) {
            $this->edgeEnvText = rtrim($this->edgeEnvText)."\n".$key.'=';
        }
        $this->dispatch('open-modal', 'env-edit');
    }

    public function discardEdgeEnv(): void
    {
        $this->authorize('update', $this->site);
        $this->edgeEnvText = $this->envText();
        $this->pending = false;
        $this->resetErrorBag('edgeEnvText');
    }

    public function redeployEdgeEnv(): void
    {
        if (! $this->saveEdgeEnvText(true)) {
            return;
        }

        $this->redeployEdge();
    }

    /** @return Collection<int, EdgeSiteEnvVar> */
    private function envRows(): Collection
    {
        return $this->envRows ??= $this->site->edgeEnvVars()->get();
    }

    /**
     * Keys across every scope. Overrides the trait's query so the linked
     * secrets list reuses the rows this component already loaded.
     *
     * @return list<string>
     */
    private function siteEnvKeys(): array
    {
        return $this->envRows()->pluck('key')->map(fn ($key): string => (string) $key)->values()->all();
    }

    private function envText(): string
    {
        return $this->envRows()
            ->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)
            ->reject(fn (EdgeSiteEnvVar $row): bool => in_array($row->key, EdgeContainerConnections::MANAGED_REDIS_KEYS, true))
            ->map(fn (EdgeSiteEnvVar $row): string => $row->key.'='.$this->quoteEnvValue($row->value))
            ->implode("\n");
    }

    /**
     * Env the next deploy adds because of the resource map. A key also
     * present in the editable block is marked overridden.
     *
     * @param  list<string>  $dashboardKeys
     * @return list<array{key: string, value: string, from: string, overridden: bool}>
     */
    private function resourceInjections(array $dashboardKeys): array
    {
        $meta = $this->site->edgeMeta();
        // Realtime reaches Worker apps too (as bindings), so it comes before the container-only rows.
        $realtime = [];
        foreach (EdgeContainerConnections::realtimeDriverEnv($this->site) as $key => $value) {
            $realtime[] = ['key' => $key, 'value' => str_ends_with($key, '_SECRET') ? '••••' : $value, 'from' => __('Realtime')];
        }
        if (($meta['runtime_mode'] ?? '') !== 'container') {
            return in_array($meta['runtime_mode'] ?? '', ['ssr', 'hybrid'], true) ? $this->markOverridden($realtime, $dashboardKeys) : [];
        }

        $rows = [];
        $engine = (string) ($meta['database']['engine'] ?? 'sql');
        $databaseHost = (string) ($meta['database']['host'] ?? '');
        if ($engine === 'postgres' && $databaseHost !== '') {
            $rows[] = ['key' => 'DB_CONNECTION', 'value' => 'pgsql', 'from' => __('Postgres')];
            $rows[] = ['key' => 'DB_HOST', 'value' => $databaseHost, 'from' => __('Postgres')];
            $rows[] = ['key' => 'DB_PASSWORD', 'value' => '••••', 'from' => __('Postgres')];
        } elseif ($engine === 'mysql' && $databaseHost !== '') {
            $rows[] = ['key' => 'DB_CONNECTION', 'value' => 'mysql', 'from' => __('MySQL')];
            $rows[] = ['key' => 'DB_HOST', 'value' => $databaseHost, 'from' => __('MySQL')];
            $rows[] = ['key' => 'DB_PASSWORD', 'value' => '••••', 'from' => __('MySQL')];
        } elseif ($engine !== 'none' && $engine !== 'postgres' && $engine !== 'mysql') {
            $rows[] = ['key' => 'DB_CONNECTION', 'value' => 'sqlite', 'from' => __('SQLite')];
            $rows[] = ['key' => 'DB_DATABASE', 'value' => '/tmp/database.sqlite', 'from' => __('SQLite')];
            $rows[] = ['key' => 'DPLY_MIGRATE_ON_BOOT', 'value' => '1', 'from' => __('SQLite')];
        }

        $url = (string) ($this->site->edgeLiveUrl() ?? '');
        if ($url !== '') {
            $rows[] = ['key' => 'APP_URL', 'value' => (string) $this->site->edgePublicUrl(), 'from' => __('App')];
            $rows[] = ['key' => 'DPLY_APP_URL', 'value' => $url, 'from' => __('App')];
        }

        $framework = (string) ($meta['build']['framework'] ?? '');
        if (! in_array($framework, ['node', 'node_generic', 'express', 'nest', 'fastify', 'koa', 'rails', 'ruby'], true)) {
            $pool = EdgeContainerSettings::phpFpmPool(EdgeContainerSettings::for($this->site)['instance_type'], $this->site);
            $rows[] = ['key' => 'DPLY_PHP_FPM_MAX_CHILDREN', 'value' => (string) $pool['max_children'], 'from' => __('App size')];
            $rows[] = ['key' => 'DPLY_PHP_MEMORY_LIMIT', 'value' => $pool['memory_limit'], 'from' => __('App size')];
        }

        foreach (EdgeContainerConnections::redisInjectionPreview($this->site) as $row) {
            $rows[] = $row;
        }

        return $this->markOverridden([...$rows, ...$realtime], $dashboardKeys);
    }

    /**
     * @param  list<array{key: string, value: string, from: string}>  $rows
     * @param  list<string>  $dashboardKeys
     * @return list<array{key: string, value: string, from: string, overridden: bool}>
     */
    private function markOverridden(array $rows, array $dashboardKeys): array
    {
        return array_map(function (array $row) use ($dashboardKeys): array {
            $row['overridden'] = in_array($row['key'], $dashboardKeys, true)
                && ! in_array($row['key'], EdgeContainerConnections::MANAGED_REDIS_KEYS, true);

            return $row;
        }, $rows);
    }

    private function quoteEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/[\s#\'"\\\\]/', $value) === 1) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }

    public function render(): View
    {
        $latest = EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first()
            ?: EdgeDeployment::query()
                ->where('site_id', $this->site->id)
                ->whereNotNull('repo_config')
                ->latest('id')
                ->first();

        $repoEnv = is_array($latest?->repo_config['env'] ?? null) ? $latest->repo_config['env'] : [];
        $sourcePath = is_array($latest?->repo_config) && is_string($latest->repo_config['source_path'] ?? null)
            ? (string) $latest->repo_config['source_path']
            : 'dply.yaml';

        // Detect missing secrets (declared in repo, no dashboard value)
        // so we can warn the user inline.
        $declaredSecretNames = is_array($repoEnv['secret'] ?? null) ? $repoEnv['secret'] : [];
        $dashboardKeys = $this->siteEnvKeys();
        $missingSecrets = array_values(array_filter(
            $declaredSecretNames,
            static fn ($name): bool => is_string($name) && ! in_array($name, $dashboardKeys, true),
        ));

        $this->pending = auth()->user()?->can('update', $this->site) === true
            && ! $this->site->isEdgePreview()
            && $this->edgeEnvText !== $this->envText();

        return view('livewire.sites.edge.workspace.environment', array_merge(
            EdgeSiteViewData::context($this->site, 'environment'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'repoEnv' => $repoEnv,
                'sourcePath' => $sourcePath,
                'missingSecrets' => $missingSecrets,
                'envKeys' => $dashboardKeys,
                'resourceInjections' => $this->resourceInjections($dashboardKeys),
            ],
        ));
    }

    protected function currentEdgeSection(): ?string
    {
        return 'environment';
    }
}
