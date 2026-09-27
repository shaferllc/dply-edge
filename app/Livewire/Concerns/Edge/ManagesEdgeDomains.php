<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Edge;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeCustomDomainProvisioner;
use App\Modules\Edge\Services\EdgeRouter;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 *
 * @property Site $site
 */
trait ManagesEdgeDomains
{
    use DispatchesToastNotifications;

    public string $edge_domain_input = '';

    public function attachEdgeDomain(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $hostname = strtolower(trim($this->edge_domain_input));
        $hostname = preg_replace('#^https?://#', '', (string) $hostname);
        $hostname = rtrim((string) $hostname, '/');
        if ($hostname === '' || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/i', $hostname)) {
            $this->toastError(__('Hostname does not look valid.'));

            return;
        }

        $backend = EdgeRouter::backendFor($this->site);
        if ($backend === null) {
            $this->toastError(__('No edge backend available for this site.'));

            return;
        }

        try {
            $backend->attachDomain($this->site->fresh(), $hostname);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->edge_domain_input = '';
        $this->site->refresh();

        $this->toastSuccess(__('Custom domain attached. Configure DNS, then verify when ready.'));
    }

    public function verifyEdgeDomain(string $hostname): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $entry = app(EdgeCustomDomainProvisioner::class)->verify($this->site->fresh(), $hostname);
        $this->site->refresh();

        $status = is_array($entry) ? (string) ($entry['dns_status'] ?? '') : '';
        if ($status === 'ready') {
            $this->toastSuccess(__('DNS verified — :hostname is live on Edge.', ['hostname' => $hostname]));

            return;
        }

        $error = is_array($entry) ? (string) ($entry['error'] ?? '') : '';
        $this->toastError($error !== '' ? $error : __('DNS verification failed. Check your CNAME and try again.'));
    }

    public function detachEdgeDomain(string $hostname): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $backend = EdgeRouter::backendFor($this->site);
        if ($backend === null) {
            $this->toastError(__('No edge backend available for this site.'));

            return;
        }

        try {
            app(EdgeCustomDomainProvisioner::class)->remove($this->site->fresh(), $hostname);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }
        $this->site->refresh();

        $this->toastSuccess(__('Custom domain removed.'));
    }

    /** Null makes the dply hostname the site's main URL again. */
    public function makeEdgeDomainPrimary(?string $hostname = null): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $site = $this->site->fresh();
        $routing = is_array($site->edgeMeta()['routing'] ?? null) ? $site->edgeMeta()['routing'] : [];
        if ($hostname !== null && ($routing['custom_domains'][$hostname]['dns_status'] ?? null) !== 'ready') {
            $this->toastError(__('Only a domain with verified DNS can be primary.'));

            return;
        }

        $routing['primary_domain'] = $hostname;
        $site->mergeEdgeMeta(['routing' => $routing]);
        $site->save();
        $this->site->refresh();

        $this->toastSuccess(__(':hostname is now the primary URL.', ['hostname' => $hostname ?? $site->edgeHostname()]));
    }
}
