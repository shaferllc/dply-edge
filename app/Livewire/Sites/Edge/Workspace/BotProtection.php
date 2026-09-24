<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\Edge\PublishesEdgeHostMap;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeTestingDomains;
use App\Modules\Edge\Support\FakeEdgeProvision;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;
use RuntimeException;
use Throwable;

class BotProtection extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    public bool $enabled = false;

    public string $site_key = '';

    public string $secret_key = '';

    public string $mode = 'forms';

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $cfg = is_array($site->edgeMeta()['turnstile'] ?? null) ? $site->edgeMeta()['turnstile'] : [];
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $this->site_key = (string) ($cfg['site_key'] ?? '');
        $this->secret_key = (string) ($cfg['secret_key'] ?? '');
        $mode = (string) ($cfg['mode'] ?? 'forms');
        $this->mode = in_array($mode, ['forms', 'all'], true) ? $mode : 'forms';
    }

    public function requestGenerateKeys(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->canGenerateKeys()) {
            $this->toastError(__('Key generation needs Dply-hosted Edge delivery and platform credentials.'));

            return;
        }

        if (trim($this->site_key) !== '' || trim($this->secret_key) !== '') {
            $this->openConfirmActionModal(
                method: 'generateKeys',
                title: __('Replace bot protection keys?'),
                message: __('This creates a new challenge widget and replaces the keys below. Save afterward to apply them to delivery.'),
                confirmLabel: __('Generate new keys'),
                destructive: true,
            );

            return;
        }

        $this->generateKeys();
    }

    public function generateKeys(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->canGenerateKeys()) {
            $this->toastError(__('Key generation needs Dply-hosted Edge delivery and platform credentials.'));

            return;
        }

        try {
            $widget = $this->createWidgetKeys();
        } catch (Throwable $e) {
            report($e);
            $this->toastError(__('Could not generate keys: :message', [
                'message' => $e->getMessage(),
            ]));

            return;
        }

        $this->site_key = $widget['sitekey'];
        $this->secret_key = $widget['secret'];
        $this->enabled = true;

        $existing = is_array($this->site->edgeMeta()['turnstile'] ?? null)
            ? $this->site->edgeMeta()['turnstile']
            : [];

        $this->site->mergeEdgeMeta([
            'turnstile' => array_merge($existing, [
                'enabled' => true,
                'site_key' => $widget['sitekey'],
                'secret_key' => $widget['secret'],
                'mode' => $this->mode,
                'widget_id' => $widget['id'],
                'generated' => true,
                'generated_at' => now()->toIso8601String(),
            ]),
        ]);
        $this->site->save();
        $this->republishEdgeHostMap();

        $this->toastSuccess(__('Bot protection keys generated and saved.'));
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->isManagedEdgeDelivery()) {
            $this->toastError(__('Bot protection requires Dply-hosted Edge delivery.'));

            return;
        }

        $this->validate([
            'site_key' => ['required_if:enabled,true', 'string', 'max:200'],
            'secret_key' => ['required_if:enabled,true', 'string', 'max:200'],
            'mode' => ['required', 'in:forms,all'],
        ]);

        $existing = is_array($this->site->edgeMeta()['turnstile'] ?? null)
            ? $this->site->edgeMeta()['turnstile']
            : [];

        $this->site->mergeEdgeMeta([
            'turnstile' => array_merge($existing, [
                'enabled' => $this->enabled,
                'site_key' => trim($this->site_key),
                'secret_key' => trim($this->secret_key),
                'mode' => $this->mode,
            ]),
        ]);
        $this->site->save();
        $this->republishEdgeHostMap();
        $this->toastSuccess(__('Bot protection saved.'));
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.bot-protection', array_merge(
            EdgeSiteViewData::context($this->site, 'bot-protection'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'managedDelivery' => $this->isManagedEdgeDelivery(),
                'canGenerateKeys' => $this->canGenerateKeys(),
            ],
        ));
    }

    protected function canGenerateKeys(): bool
    {
        if (! $this->isManagedEdgeDelivery()) {
            return false;
        }

        if (FakeEdgeProvision::enabled()) {
            return true;
        }

        return trim((string) config('edge.cloudflare.account_id')) !== ''
            && trim((string) config('edge.cloudflare.api_token')) !== '';
    }

    /**
     * @return array{id: string, sitekey: string, secret: string}
     */
    protected function createWidgetKeys(): array
    {
        if (FakeEdgeProvision::enabled()) {
            $suffix = Str::lower(Str::random(8));

            return [
                'id' => 'fake-'.$suffix,
                'sitekey' => '0x4AAAAAAAFakeSite'.$suffix,
                'secret' => '0x4AAAAAAAFakeSecret'.$suffix,
            ];
        }

        $domains = $this->turnstileDomainsForSite();
        if ($domains === []) {
            throw new RuntimeException(__('This site has no Edge hostnames to attach to a challenge widget.'));
        }

        $name = 'dply-edge-'.Str::slug((string) ($this->site->slug ?: $this->site->name ?: $this->site->id));
        if (strlen($name) > 60) {
            $name = substr($name, 0, 60);
        }

        $widget = EdgeCloudflareClient::fromConfig()->createTurnstileWidget($name, $domains, 'managed');

        return [
            'id' => $widget['id'] !== '' ? $widget['id'] : $widget['sitekey'],
            'sitekey' => $widget['sitekey'],
            'secret' => $widget['secret'],
        ];
    }

    /**
     * @return list<string>
     */
    protected function turnstileDomainsForSite(): array
    {
        $domains = [];

        foreach ($this->site->edgeUsageHostnameZones() as $hostname => $zone) {
            $hostname = strtolower(trim((string) $hostname));
            $zone = strtolower(trim((string) $zone));
            if ($hostname !== '') {
                $domains[] = $hostname;
            }
            if ($zone !== '') {
                $domains[] = $zone;
            }
        }

        $apex = strtolower(trim(EdgeTestingDomains::defaultApex()));
        if ($apex !== '') {
            $domains[] = $apex;
        }

        $workerZone = strtolower(trim((string) config('edge.cloudflare.worker_zone_name')));
        if ($workerZone !== '') {
            $domains[] = $workerZone;
        }

        return array_values(array_unique(array_filter($domains)));
    }
}
