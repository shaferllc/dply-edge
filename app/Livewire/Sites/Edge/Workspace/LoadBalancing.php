<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\Edge\PublishesEdgeHostMap;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Jobs\SyncEdgeLoadBalancerJob;
use App\Modules\Edge\Services\EdgeLoadBalancerProvisioner;
use App\Modules\Edge\Support\EdgeLoadBalancing;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Cloudflare Load Balancing in front of a hybrid site's origin servers.
 * Paid add-on, billed per endpoint (subscription.standard.edge_lb_endpoint_cents).
 */
class LoadBalancing extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    public bool $enabled = false;

    public string $steering = 'random';

    public string $health_path = '/';

    public string $expected_codes = '2xx';

    /** @var list<array{name: string, address: string, port: int|string|null, weight: float|string, enabled: bool, host_header: string}> */
    public array $endpoints = [];

    /** @var array<string, array{healthy: bool, pops: int, failure_reason: ?string}>|null */
    public ?array $health = null;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $cfg = EdgeLoadBalancing::config($site);
        $this->enabled = $cfg['enabled'];
        $this->steering = $cfg['steering'];
        $this->health_path = $cfg['health']['path'];
        $this->expected_codes = $cfg['health']['expected_codes'];
        $this->endpoints = $cfg['endpoints'];
    }

    public function addEndpoint(): void
    {
        if (count($this->endpoints) < EdgeLoadBalancing::MAX_ENDPOINTS) {
            $this->endpoints[] = ['name' => 'origin-'.(count($this->endpoints) + 1), 'address' => '', 'port' => null, 'weight' => 1, 'enabled' => true, 'host_header' => ''];
        }
    }

    public function removeEndpoint(int $index): void
    {
        unset($this->endpoints[$index]);
        $this->endpoints = array_values($this->endpoints);
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        $blocked = $this->blockedReason($this->enabled);
        if ($blocked !== null) {
            $this->toastError($blocked);

            return;
        }

        $host = 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i';
        $this->validate([
            'steering' => ['required', Rule::in(EdgeLoadBalancing::STEERING)],
            'health_path' => ['required', 'string', 'max:200', 'starts_with:/'],
            'expected_codes' => ['required', 'regex:/^[1-5](\d{2}|xx)$/'],
            'endpoints' => ['array', 'max:'.EdgeLoadBalancing::MAX_ENDPOINTS, $this->enabled ? 'min:1' : 'min:0'],
            'endpoints.*.name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/', 'distinct'],
            'endpoints.*.address' => ['required', 'string', 'max:253', 'distinct', function (string $attribute, mixed $value, \Closure $fail) use ($host): void {
                $value = (string) $value;
                $isIp = filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
                if (! $isIp && validator(['a' => $value], ['a' => $host])->fails()) {
                    $fail(__('Use a public IP address or hostname (no https://, no path).'));
                }
            }],
            'endpoints.*.port' => ['nullable', 'integer', 'between:1,65535'],
            'endpoints.*.weight' => ['required', 'numeric', 'between:0,1'],
            'endpoints.*.host_header' => ['nullable', 'string', 'max:253', $host],
        ], [
            'endpoints.min' => __('Add at least one endpoint to enable load balancing.'),
        ]);

        $current = is_array($this->site->edgeMeta()['load_balancing'] ?? null) ? $this->site->edgeMeta()['load_balancing'] : [];
        $this->site->mergeEdgeMeta([
            'load_balancing' => array_merge($current, [
                'enabled' => $this->enabled,
                'steering' => $this->steering,
                'health' => ['path' => $this->health_path, 'expected_codes' => strtolower($this->expected_codes), 'interval' => 60],
                'endpoints' => $this->endpoints,
                // An active LB keeps serving (and billing) while the edit syncs.
                'status' => $this->enabled && ($current['status'] ?? '') !== 'active' ? 'pending' : ($current['status'] ?? 'disabled'),
            ]),
        ]);
        $this->site->save();

        SyncEdgeLoadBalancerJob::dispatch((string) $this->site->id);
        $this->health = null;
        $this->toastSuccess($this->enabled
            ? __('Saved. Cloudflare is setting up the load balancer.')
            : __('Saved. The load balancer is being removed.'));
    }

    public function refreshHealth(EdgeLoadBalancerProvisioner $provisioner): void
    {
        $this->authorize('view', $this->site);
        try {
            $this->health = $provisioner->health($this->site->fresh());
        } catch (\Throwable $e) {
            $this->toastError(__('Could not read health from Cloudflare: :error', ['error' => $e->getMessage()]));
        }
    }

    /** Why this save can't go through, or null. Turning it off is always allowed. */
    private function blockedReason(bool $enabling): ?string
    {
        if (! $this->isManagedEdgeDelivery()) {
            return __('Load balancing requires Dply-hosted Edge delivery.');
        }
        if (! $enabling) {
            return null;
        }
        if (($this->site->edgeMeta()['runtime_mode'] ?? 'static') !== 'hybrid') {
            return __('Load balancing fronts a hybrid origin. Convert this site to hybrid under Delivery first.');
        }
        $org = $this->site->organization;
        if ($org === null || ! ($org->tierAllowances()['addons'] ?? false) || ! $org->onAnyPaidPlan()) {
            return __('Load balancing is available on Pro and Team. Choose a plan on the organization billing page first.');
        }

        return null;
    }

    public function render(): View
    {
        $cfg = EdgeLoadBalancing::config($this->site->fresh());

        return view('livewire.sites.edge.workspace.load-balancing', array_merge(
            EdgeSiteViewData::context($this->site, 'edge-load-balancing'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'managedDelivery' => $this->isManagedEdgeDelivery(),
                'blocked' => $this->blockedReason(true),
                'status' => $cfg['status'],
                'error' => $cfg['error'],
                'lbHostname' => $cfg['cf']['hostname'] ?? null,
                'unitCents' => (int) config('subscription.standard.edge_lb_endpoint_cents', 800),
                'steeringOptions' => [
                    'random' => __('Random (weighted)'),
                    'least_outstanding_requests' => __('Least outstanding requests'),
                    'least_connections' => __('Least connections'),
                    'hash' => __('Hash (sticky by client IP)'),
                ],
            ],
        ));
    }
}
