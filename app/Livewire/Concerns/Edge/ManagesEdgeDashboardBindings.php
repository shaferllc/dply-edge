<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Edge;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeDashboardBindingProvisioner;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeEffectiveBindings;
use App\Modules\Edge\Support\EdgeSiteHasWorker;
use Throwable;

/**
 * Dashboard binding add/detach for in-context modals
 * (e.g. Jobs). Persists to {@see Site} edgeMeta `connections`.
 *
 * @property Site $site
 */
trait ManagesEdgeDashboardBindings
{
    /** @var list<array{name: string, kind: string, value: string}> */
    public array $dashboard_bindings = [];

    public string $new_name = '';

    public string $new_kind = 'kv';

    public string $new_value = '';

    /** When true, `new_value` is a resource name to create rather than an existing identifier. */
    public bool $create_resource = false;

    protected function refreshEdgeDashboardBindingsFromMeta(): void
    {
        $this->dashboard_bindings = array_map(
            static fn (array $row): array => [
                'name' => $row['name'],
                'kind' => $row['kind'],
                'value' => $row['value'],
            ],
            EdgeEffectiveBindings::dashboardOverrides($this->site),
        );
    }

    public function addBinding(EdgeDashboardBindingProvisioner $provisioner): void
    {
        $this->authorize('update', $this->site);

        $name = trim($this->new_name);
        $kind = trim($this->new_kind);
        $value = trim($this->new_value);

        if ($name === '') {
            $this->addError('new_name', __('Binding name is required.'));

            return;
        }
        // Worker bindings surface as `env.NAME`, so the name has to be a legal
        // JS identifier or the script fails to boot at the edge.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            $this->addError('new_name', __('Name must start with a letter or underscore and contain only letters, numbers, and underscores.'));

            return;
        }
        if (in_array($name, EdgeEffectiveBindings::RESERVED_NAMES, true)) {
            $this->addError('new_name', __('That name is reserved by the dply Edge runtime.'));

            return;
        }
        if (! in_array($kind, EdgeEffectiveBindings::KINDS, true)) {
            $this->addError('new_kind', __('Unknown binding type.'));

            return;
        }
        if ($value === '') {
            $this->addError('new_value', $this->create_resource
                ? __('Give the resource a name to create.')
                : __('Enter the resource identifier to attach.'));

            return;
        }
        foreach (EdgeContainerConnections::for($this->site) as $existing) {
            if ($existing['name'] === $name) {
                $this->addError('new_name', __('A binding with that name already exists.'));

                return;
            }
        }
        // A repo-declared binding of the same name would win at deploy time and
        // this row would be silently dropped — say so now instead.
        foreach ($this->edgeRepoBindings() as $repo) {
            if ($repo['name'] === $name) {
                $this->addError('new_name', __('wrangler.toml already declares that binding; the repo file wins.'));

                return;
            }
        }

        if ($this->create_resource) {
            try {
                $value = $provisioner->create($this->site, $kind, $value);
            } catch (Throwable $e) {
                $this->addError('new_value', __('Could not create the resource: :msg', ['msg' => $e->getMessage()]));

                return;
            }
        }

        $this->dashboard_bindings[] = ['name' => $name, 'kind' => $kind, 'value' => $value];
        $this->persistEdgeDashboardBindings();

        $this->new_name = '';
        $this->new_value = '';
        $this->create_resource = false;
        $this->toastSuccess(__('Binding added — applied on the next deploy.'));
        $this->afterEdgeDashboardBindingAdded($name, $kind);
    }

    /**
     * Detaches the binding from the site. The underlying resource is left alone
     * (it may hold data or be shared). Deleting the resource is a separate act.
     */
    public function removeBinding(int $index): void
    {
        $this->authorize('update', $this->site);

        if (! isset($this->dashboard_bindings[$index])) {
            return;
        }
        array_splice($this->dashboard_bindings, $index, 1);
        $this->persistEdgeDashboardBindings();
        $this->toastSuccess(__('Binding detached — the resource was left in place.'));
    }

    protected function persistEdgeDashboardBindings(): void
    {
        $previous = EdgeEffectiveBindings::dashboardOverrides($this->site);
        $kinds = array_flip(EdgeEffectiveBindings::KIND_FOR_CONNECTION);
        $wanted = array_column($this->dashboard_bindings, null, 'name');

        foreach ($previous as $row) {
            if (! isset($wanted[$row['name']])) {
                EdgeContainerConnections::detach($this->site, $row['name']);
            }
        }
        $had = array_column($previous, 'name');
        foreach ($this->dashboard_bindings as $row) {
            if (! in_array($row['name'], $had, true)) {
                EdgeContainerConnections::attach($this->site, $kinds[$row['kind']], $row['name'], $row['value']);
            }
        }

        audit_log(
            $this->site->organization,
            auth()->user(),
            'site.edge.bindings.updated',
            $this->site,
            ['connections' => $previous],
            ['connections' => $this->dashboard_bindings],
        );

        $this->refreshEdgeDashboardBindingsFromMeta();
    }

    protected function latestEdgeConfigDeployment(): ?EdgeDeployment
    {
        return EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first()
            ?: EdgeDeployment::query()
                ->where('site_id', $this->site->id)
                ->whereNotNull('repo_config')
                ->latest('id')
                ->first();
    }

    /**
     * @return list<array{name: string, kind: string, value: string, source: string}>
     */
    protected function edgeRepoBindings(): array
    {
        return array_values(array_filter(
            EdgeEffectiveBindings::for($this->site, $this->latestEdgeConfigDeployment()),
            static fn (array $b): bool => $b['source'] === 'repo',
        ));
    }

    /**
     * Bindings are injected into the *per-deployment Worker script*, which only
     * exists for SSR sites and for static/hybrid sites that ship a
     * `middleware.ts`. A purely static site serves assets straight from R2 with
     * no Worker of its own, so a binding there would never be reachable.
     */
    protected function edgeSiteHasWorker(): bool
    {
        return EdgeSiteHasWorker::for($this->site, $this->latestEdgeConfigDeployment());
    }

    /**
     * Hook for hosts (e.g. Jobs) to sync related form fields after a bind.
     */
    protected function afterEdgeDashboardBindingAdded(string $name, string $kind): void
    {
        //
    }
}
