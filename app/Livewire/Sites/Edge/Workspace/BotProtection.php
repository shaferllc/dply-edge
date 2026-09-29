<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

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
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    public bool $enabled = false;

    public string $site_key = '';

    public string $secret_key = '';

    public string $mode = 'forms';

    /** Setting open in the modal: mode or keys. */
    public ?string $editingSetting = null;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->loadFromSite();
    }

    /** Saved config → component state; drops unsaved edits. */
    private function loadFromSite(): void
    {
        $cfg = is_array($this->site->edgeMeta()['turnstile'] ?? null) ? $this->site->edgeMeta()['turnstile'] : [];
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $this->site_key = (string) ($cfg['site_key'] ?? '');
        // Public props ship in the Livewire snapshot: only editors get the secret.
        $this->secret_key = auth()->user()?->can('update', $this->site) ? (string) ($cfg['secret_key'] ?? '') : '';
        $mode = (string) ($cfg['mode'] ?? 'forms');
        $this->mode = in_array($mode, ['forms', 'all'], true) ? $mode : 'forms';
    }

    public function editSetting(string $setting): void
    {
        abort_unless(in_array($setting, ['mode', 'keys'], true), 404);
        $this->authorize('update', $this->site);
        $this->loadFromSite();
        $this->resetErrorBag();
        $this->editingSetting = $setting;
        $this->dispatch('open-modal', 'edge-bot-protection');
    }

    public function closeSetting(): void
    {
        $this->loadFromSite();
        $this->editingSetting = null;
        $this->dispatch('close-modal', 'edge-bot-protection');
    }

    public function saveSetting(): void
    {
        if ($this->save()) {
            $this->editingSetting = null;
            $this->dispatch('close-modal', 'edge-bot-protection');
        }
    }

    /** The on/off checkbox saves at once; turning on without keys generates them when it can. */
    public function updatedEnabled(): void
    {
        if ($this->enabled && (trim($this->site_key) === '' || trim($this->secret_key) === '')) {
            if ($this->canGenerateKeys()) {
                $this->generateKeys();

                return;
            }
            $this->enabled = false;
            $this->toastError(__('Add keys first: open Keys and paste a site key and secret.'));

            return;
        }
        $this->save();
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

        $this->editingSetting = null;
        $this->dispatch('close-modal', 'edge-bot-protection');
        $this->toastSuccess(__('Bot protection keys generated and saved.'));
    }

    public function save(): bool
    {
        $this->authorize('update', $this->site);
        if (! $this->isManagedEdgeDelivery()) {
            $this->toastError(__('Bot protection requires Dply-hosted Edge delivery.'));

            return false;
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

        return true;
    }

    /**
     * Other settings on this app that ask for a bot check: forms with
     * "Require bot check" and rate-limit rules that challenge.
     *
     * @return list<array{kind: string, path: string}>
     */
    private function dependents(): array
    {
        $meta = $this->site->edgeMeta();
        $out = [];
        foreach ((array) ($meta['forms']['endpoints'] ?? []) as $e) {
            if (is_array($e) && ($e['require_turnstile'] ?? false)) {
                $out[] = ['kind' => 'form', 'path' => (string) ($e['path'] ?? '')];
            }
        }
        foreach ((array) ($meta['rate_limit']['rules'] ?? []) as $r) {
            if (is_array($r) && ($r['action'] ?? '') === 'challenge') {
                $out[] = ['kind' => 'rate', 'path' => (string) ($r['path'] ?? '')];
            }
        }

        return $out;
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
                'dependents' => $this->dependents(),
                'keysGenerated' => (bool) ($this->site->edgeMeta()['turnstile']['generated'] ?? false),
                'keysGeneratedAt' => $this->site->edgeMeta()['turnstile']['generated_at'] ?? null,
                'hasKeys' => trim((string) ($this->site->edgeMeta()['turnstile']['site_key'] ?? '')) !== ''
                    && trim((string) ($this->site->edgeMeta()['turnstile']['secret_key'] ?? '')) !== '',
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
