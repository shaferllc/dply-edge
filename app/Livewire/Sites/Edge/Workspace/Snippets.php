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

class Snippets extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    public bool $enabled = false;

    /** @var list<array{name: string, phase: string, path: string, html: string}> */
    public array $items = [];

    /**
     * Starter HTML operators can one-click add. Placeholders in the markup
     * must be replaced before Save.
     *
     * @return list<array{key: string, name: string, label: string, phase: string, path: string, html: string, hint: string}>
     */
    public static function exampleCatalog(): array
    {
        return [
            [
                'key' => 'meta',
                'name' => 'Basic SEO meta',
                'label' => 'Meta',
                'phase' => 'head',
                'path' => '/*',
                'html' => <<<'HTML'
<meta name="description" content="Replace with your site description.">
<meta property="og:title" content="Replace with your page title">
<meta property="og:description" content="Replace with your social description.">
HTML,
                'hint' => __('Common description + Open Graph tags in <head>.'),
            ],
            [
                'key' => 'noindex',
                'name' => 'Noindex',
                'label' => 'Noindex',
                'phase' => 'head',
                'path' => '/*',
                'html' => '<meta name="robots" content="noindex, nofollow">',
                'hint' => __('Keep staging / preview-like hosts out of search indexes.'),
            ],
            [
                'key' => 'banner',
                'name' => 'Announcement banner',
                'label' => 'Banner',
                'phase' => 'body',
                'path' => '/*',
                'html' => <<<'HTML'
<div style="background:#111;color:#fff;text-align:center;padding:10px 16px;font:14px/1.4 system-ui,sans-serif">
  Shipping something new — <a href="/blog" style="color:#fff;text-decoration:underline">read the update</a>.
</div>
HTML,
                'hint' => __('Simple top-of-body notice. Narrow the path if you only want it on marketing pages.'),
            ],
            [
                'key' => 'jsonld',
                'name' => 'JSON-LD Organization',
                'label' => 'JSON-LD',
                'phase' => 'head',
                'path' => '/*',
                'html' => <<<'HTML'
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "Your Company",
  "url": "https://example.com",
  "logo": "https://example.com/logo.png"
}
</script>
HTML,
                'hint' => __('Structured data for search. Update name/url/logo.'),
            ],
        ];
    }

    /** Index into $items open in the edit modal, or null. */
    public ?int $editingItem = null;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->loadFromSite();
    }

    /** Saved config → component state; drops unsaved edits. */
    private function loadFromSite(): void
    {
        $cfg = is_array($this->site->edgeMeta()['snippets'] ?? null) ? $this->site->edgeMeta()['snippets'] : [];
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $this->items = array_values(array_map(fn ($i) => [
            'name' => (string) ($i['name'] ?? 'snippet'),
            'phase' => in_array(($i['phase'] ?? 'head'), ['head', 'body'], true) ? (string) $i['phase'] : 'head',
            'path' => (string) ($i['path'] ?? '/*'),
            'html' => (string) ($i['html'] ?? ''),
        ], is_array($cfg['items'] ?? null) ? $cfg['items'] : []));
    }

    /** "+ Add to head / body": a blank snippet in that slot, opened in the modal. */
    public function newItem(string $phase): void
    {
        $this->loadFromSite();
        $this->resetErrorBag();
        $this->items[] = ['name' => '', 'phase' => $phase === 'body' ? 'body' : 'head', 'path' => '/*', 'html' => ''];
        $this->editingItem = array_key_last($this->items);
        $this->dispatch('open-modal', 'edge-snippet');
    }

    public function editItem(int $index): void
    {
        $this->loadFromSite();
        abort_unless(isset($this->items[$index]), 404);
        $this->resetErrorBag();
        $this->editingItem = $index;
        $this->dispatch('open-modal', 'edge-snippet');
    }

    /** Fill the snippet being edited from a starter example. */
    public function useExample(string $key): void
    {
        $example = collect(self::exampleCatalog())->firstWhere('key', $key);
        if (! is_array($example) || $this->editingItem === null) {
            return;
        }

        $this->items[$this->editingItem] = [
            'name' => (string) $example['name'],
            'phase' => (string) $example['phase'],
            'path' => (string) $example['path'],
            'html' => (string) $example['html'],
        ];
    }

    public function closeItem(): void
    {
        $this->loadFromSite();
        $this->editingItem = null;
        $this->dispatch('close-modal', 'edge-snippet');
    }

    /** Modal Save: persists every snippet (the edited one included) and closes. */
    public function saveItem(): void
    {
        if ($this->editingItem !== null) {
            $this->validate(["items.{$this->editingItem}.html" => ['required']], [], ["items.{$this->editingItem}.html" => __('HTML')]);
            // A new site's first snippet should actually load.
            if (collect($this->site->edgeMeta()['snippets']['items'] ?? [])->isEmpty()) {
                $this->enabled = true;
            }
        }

        if ($this->save()) {
            $this->editingItem = null;
            $this->dispatch('close-modal', 'edge-snippet');
        }
    }

    public function removeEditingItem(): void
    {
        if ($this->editingItem === null) {
            return;
        }
        unset($this->items[$this->editingItem]);
        $this->items = array_values($this->items);
        $this->editingItem = null;
        if ($this->save()) {
            $this->dispatch('close-modal', 'edge-snippet');
        }
    }

    public function updatedEnabled(): void
    {
        $this->save();
    }

    public function save(): bool
    {
        $this->authorize('update', $this->site);
        if (! $this->isManagedEdgeDelivery()) {
            $this->toastError(__('Snippets require Dply-hosted Edge delivery.'));

            return false;
        }

        $this->validate([
            'items.*.name' => ['required', 'string', 'max:64'],
            'items.*.phase' => ['required', 'in:head,body'],
            'items.*.path' => ['required', 'string', 'max:255'],
            'items.*.html' => ['nullable', 'string', 'max:8000'],
        ]);

        $this->site->mergeEdgeMeta([
            'snippets' => [
                'enabled' => $this->enabled,
                'items' => array_values(array_filter(
                    $this->items,
                    static fn (array $i): bool => trim((string) $i['html']) !== '',
                )),
            ],
        ]);
        $this->site->save();
        $this->republishEdgeHostMap();
        $this->toastSuccess(__('Snippets saved.'));

        return true;
    }

    public function render(): View
    {
        $repo = $this->edgeRepoConfigSection('snippets');

        return view('livewire.sites.edge.workspace.snippets', array_merge(
            EdgeSiteViewData::context($this->site, 'snippets'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'managedDelivery' => $this->isManagedEdgeDelivery(),
                'examples' => self::exampleCatalog(),
                'sourcePath' => $repo['source_path'],
                'repoSnippets' => $repo['section'],
            ],
        ));
    }
}
