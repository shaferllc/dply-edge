<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\Edge\PublishesEdgeHostMap;
use App\Models\EdgeFormSubmission;
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

    /** Index into $endpoints open in the edit modal, or null. */
    public ?int $editingEndpoint = null;

    /** The modal is showing the starter picker (Add a form). */
    public bool $pickingEndpoint = false;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->loadFromSite();
    }

    /** Saved config → component state; drops unsaved edits. */
    private function loadFromSite(): void
    {
        $cfg = is_array($this->site->edgeMeta()['forms'] ?? null) ? $this->site->edgeMeta()['forms'] : [];
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $this->endpoints = array_values(array_map(fn ($e) => [
            'path' => (string) ($e['path'] ?? '/contact'),
            'to_email' => (string) ($e['to_email'] ?? ''),
            'honeypot' => (string) ($e['honeypot'] ?? 'company'),
            'require_turnstile' => (bool) ($e['require_turnstile'] ?? true),
        ], is_array($cfg['endpoints'] ?? null) ? $cfg['endpoints'] : []));
    }

    public function openPicker(): void
    {
        $this->loadFromSite();
        $this->resetErrorBag();
        $this->editingEndpoint = null;
        $this->pickingEndpoint = true;
        $this->dispatch('open-modal', 'edge-form-endpoint');
    }

    public function editEndpoint(int $index): void
    {
        $this->loadFromSite();
        abort_unless(isset($this->endpoints[$index]), 404);
        $this->resetErrorBag();
        $this->pickingEndpoint = false;
        $this->editingEndpoint = $index;
        $this->dispatch('open-modal', 'edge-form-endpoint');
    }

    public function closeEndpoint(): void
    {
        $this->loadFromSite();
        $this->editingEndpoint = null;
        $this->pickingEndpoint = false;
        $this->dispatch('close-modal', 'edge-form-endpoint');
    }

    /** Modal Save: persists every endpoint (the edited one included) and closes. */
    public function saveEndpoint(): void
    {
        if ($this->save()) {
            $this->editingEndpoint = null;
            $this->dispatch('close-modal', 'edge-form-endpoint');
        }
    }

    public function removeEditingEndpoint(): void
    {
        if ($this->editingEndpoint === null) {
            return;
        }
        unset($this->endpoints[$this->editingEndpoint]);
        $this->endpoints = array_values($this->endpoints);
        $this->saveEndpoint();
    }

    public function updatedEnabled(): void
    {
        $this->save();
    }

    public function addEndpoint(): void
    {
        $this->endpoints[] = [
            'path' => '/contact',
            'to_email' => (string) (auth()->user()->email ?? ''),
            'honeypot' => 'company',
            'require_turnstile' => true,
        ];
        $this->enabled = true;
        $this->pickingEndpoint = false;
        $this->editingEndpoint = array_key_last($this->endpoints);
    }

    public function addExample(string $key): void
    {
        $example = collect(self::exampleCatalog())->firstWhere('key', $key);
        if (! is_array($example)) {
            return;
        }

        $this->endpoints[] = [
            'path' => (string) $example['path'],
            'to_email' => trim((string) ($this->endpoints[0]['to_email'] ?? '')) ?: (string) (auth()->user()->email ?? ''),
            'honeypot' => (string) $example['honeypot'],
            'require_turnstile' => (bool) $example['require_turnstile'],
        ];
        $this->enabled = true;
        $this->pickingEndpoint = false;
        $this->editingEndpoint = array_key_last($this->endpoints);
    }

    /** Copy-paste HTML for one endpoint, posting to the live Edge hostname. */
    private function sampleHtml(array $endpoint): string
    {
        $liveUrl = rtrim((string) ($this->site->edgeLiveUrl() ?? ''), '/');
        $path = '/'.ltrim((string) $endpoint['path'], '/');
        $action = ($liveUrl !== '' ? $liveUrl : 'https://your-site.on-dply.live').$path;
        $honeypot = trim((string) $endpoint['honeypot']) ?: 'company';

        $html = '<form method="POST" action="'.e($action).'">'."\n"
            .'  <label>Name <input type="text" name="name" required></label>'."\n"
            .'  <label>Email <input type="email" name="email" required></label>'."\n"
            .'  <label>Message <textarea name="message" required></textarea></label>'."\n"
            .'  <!-- Honeypot: leave empty; hide from humans -->'."\n"
            .'  <input type="text" name="'.e($honeypot).'" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">'."\n";
        if ($endpoint['require_turnstile'] ?? false) {
            $html .= '  <!-- Require bot check: add Turnstile widget (Bot protection) -->'."\n"
                .'  <div class="cf-turnstile" data-sitekey="YOUR_TURNSTILE_SITE_KEY"></div>'."\n"
                .'  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>'."\n";
        }

        return $html.'  <button type="submit">Send</button>'."\n".'</form>';
    }

    public function save(): bool
    {
        $this->authorize('update', $this->site);
        if (! $this->isManagedEdgeDelivery()) {
            $this->toastError(__('Forms require Dply-hosted Edge delivery.'));

            return false;
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

        return true;
    }

    public function render(): View
    {
        $repo = $this->edgeRepoConfigSection('forms');

        return view('livewire.sites.edge.workspace.forms', array_merge(
            EdgeSiteViewData::context($this->site, 'forms'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'managedDelivery' => $this->isManagedEdgeDelivery(),
                'examples' => self::exampleCatalog(),
                'editingSampleHtml' => isset($this->endpoints[$this->editingEndpoint ?? -1])
                    ? $this->sampleHtml($this->endpoints[$this->editingEndpoint])
                    : null,
                'sourcePath' => $repo['source_path'],
                'repoForms' => $repo['section'],
                'submissions' => EdgeFormSubmission::query()
                    ->where('site_id', $this->site->id)
                    ->latest()
                    ->limit(20)
                    ->get(),
            ],
        ));
    }
}
