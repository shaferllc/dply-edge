<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\Edge\PublishesEdgeHostMap;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Forms extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    public bool $enabled = false;

    /** @var list<array{path: string, to_email: string, honeypot: string, require_turnstile: bool}> */
    public array $endpoints = [];

    /**
     * One-click endpoint starters. HTML samples are built in the view from the
     * live Edge hostname + the saved path / honeypot.
     *
     * @return list<array{key: string, label: string, path: string, honeypot: string, require_turnstile: bool, hint: string}>
     */
    public static function exampleCatalog(): array
    {
        return [
            [
                'key' => 'contact',
                'label' => 'Contact',
                'path' => '/contact',
                'honeypot' => 'company',
                'require_turnstile' => true,
                'hint' => __('General contact form → /contact with honeypot + bot check.'),
            ],
            [
                'key' => 'newsletter',
                'label' => 'Newsletter',
                'path' => '/newsletter',
                'honeypot' => 'website',
                'require_turnstile' => true,
                'hint' => __('Email signup endpoint at /newsletter.'),
            ],
            [
                'key' => 'support',
                'label' => 'Support',
                'path' => '/api/support',
                'honeypot' => 'fax',
                'require_turnstile' => true,
                'hint' => __('Support inbox at /api/support (good for rate-limit pairing).'),
            ],
            [
                'key' => 'simple',
                'label' => 'Simple (no bot check)',
                'path' => '/feedback',
                'honeypot' => 'company',
                'require_turnstile' => false,
                'hint' => __('Honeypot only — use when Turnstile is not set up yet.'),
            ],
        ];
    }

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $cfg = is_array($site->edgeMeta()['forms'] ?? null) ? $site->edgeMeta()['forms'] : [];
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $endpoints = is_array($cfg['endpoints'] ?? null) ? $cfg['endpoints'] : [];
        $this->endpoints = $endpoints !== [] ? array_values(array_map(fn ($e) => [
            'path' => (string) ($e['path'] ?? '/contact'),
            'to_email' => (string) ($e['to_email'] ?? ''),
            'honeypot' => (string) ($e['honeypot'] ?? 'company'),
            'require_turnstile' => (bool) ($e['require_turnstile'] ?? true),
        ], $endpoints)) : [[
            'path' => '/contact',
            'to_email' => (string) (auth()->user()->email ?? ''),
            'honeypot' => 'company',
            'require_turnstile' => true,
        ]];
    }

    public function addEndpoint(): void
    {
        $this->endpoints[] = [
            'path' => '/contact',
            'to_email' => (string) (auth()->user()->email ?? ''),
            'honeypot' => 'company',
            'require_turnstile' => true,
        ];
    }

    public function addExample(string $key): void
    {
        $example = collect(self::exampleCatalog())->firstWhere('key', $key);
        if (! is_array($example)) {
            return;
        }

        $defaultEmail = trim((string) ($this->endpoints[0]['to_email'] ?? ''))
            ?: (string) (auth()->user()->email ?? '');

        $row = [
            'path' => (string) $example['path'],
            'to_email' => $defaultEmail,
            'honeypot' => (string) $example['honeypot'],
            'require_turnstile' => (bool) $example['require_turnstile'],
        ];

        $onlyDefaultPlaceholder = count($this->endpoints) === 1
            && trim((string) $this->endpoints[0]['path']) === '/contact'
            && trim((string) $this->endpoints[0]['to_email']) === '';

        if ($onlyDefaultPlaceholder) {
            $this->endpoints = [$row];
        } else {
            $this->endpoints[] = $row;
        }

        $this->enabled = true;
    }

    public function removeEndpoint(int $index): void
    {
        unset($this->endpoints[$index]);
        $this->endpoints = array_values($this->endpoints);
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->isManagedEdgeDelivery()) {
            $this->toastError(__('Forms require Dply-hosted Edge delivery.'));

            return;
        }

        $this->validate([
            'endpoints.*.path' => ['required', 'string', 'max:255'],
            'endpoints.*.to_email' => ['required', 'email'],
            'endpoints.*.honeypot' => ['nullable', 'string', 'max:64'],
        ]);

        $this->site->mergeEdgeMeta([
            'forms' => [
                'enabled' => $this->enabled,
                'endpoints' => $this->endpoints,
            ],
        ]);
        $this->site->save();
        $this->republishEdgeHostMap();
        $this->toastSuccess(__('Forms saved.'));
    }

    public function render(): View
    {
        $liveUrl = rtrim((string) ($this->site->edgeLiveUrl() ?? ''), '/');
        $primary = $this->endpoints[0] ?? null;
        $samplePath = is_array($primary) ? (string) $primary['path'] : '/contact';
        if ($samplePath === '' || ! str_starts_with($samplePath, '/')) {
            $samplePath = '/'.$samplePath;
        }
        $sampleHoneypot = is_array($primary)
            ? (trim((string) $primary['honeypot']) ?: 'company')
            : 'company';
        $sampleRequireBot = is_array($primary) ? (bool) $primary['require_turnstile'] : false;
        $sampleAction = $liveUrl !== '' ? $liveUrl.$samplePath : 'https://your-site.on-dply.live'.$samplePath;

        $repo = $this->edgeRepoConfigSection('forms');

        return view('livewire.sites.edge.workspace.forms', array_merge(
            EdgeSiteViewData::context($this->site, 'forms'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'managedDelivery' => $this->isManagedEdgeDelivery(),
                'examples' => self::exampleCatalog(),
                'sampleAction' => $sampleAction,
                'samplePath' => $samplePath,
                'sampleHoneypot' => $sampleHoneypot,
                'sampleRequireBot' => $sampleRequireBot,
                'liveHostname' => $liveUrl !== '' ? preg_replace('#^https?://#', '', $liveUrl) : null,
                'sourcePath' => $repo['source_path'],
                'repoForms' => $repo['section'],
            ],
        ));
    }
}
