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

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $cfg = is_array($site->edgeMeta()['snippets'] ?? null) ? $site->edgeMeta()['snippets'] : [];
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $items = is_array($cfg['items'] ?? null) ? $cfg['items'] : [];
        $this->items = $items !== [] ? array_values(array_map(fn ($i) => [
            'name' => (string) ($i['name'] ?? 'snippet'),
            'phase' => in_array(($i['phase'] ?? 'head'), ['head', 'body'], true) ? (string) $i['phase'] : 'head',
            'path' => (string) ($i['path'] ?? '/*'),
            'html' => (string) ($i['html'] ?? ''),
        ], $items)) : [[
            'name' => 'custom',
            'phase' => 'head',
            'path' => '/*',
            'html' => '',
        ]];
    }

    public function addItem(): void
    {
        $this->items[] = ['name' => 'snippet', 'phase' => 'head', 'path' => '/*', 'html' => ''];
    }

    public function addExample(string $key): void
    {
        $example = collect(self::exampleCatalog())->firstWhere('key', $key);
        if (! is_array($example)) {
            return;
        }

        $row = [
            'name' => (string) $example['name'],
            'phase' => in_array($example['phase'], ['head', 'body'], true)
                ? (string) $example['phase']
                : 'head',
            'path' => (string) $example['path'],
            'html' => (string) $example['html'],
        ];

        $onlyBlankPlaceholder = count($this->items) === 1
            && trim((string) $this->items[0]['html']) === ''
            && in_array(trim((string) $this->items[0]['name']), ['', 'custom', 'snippet'], true);

        if ($onlyBlankPlaceholder) {
            $this->items = [$row];
        } else {
            $this->items[] = $row;
        }

        $this->enabled = true;
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->isManagedEdgeDelivery()) {
            $this->toastError(__('Snippets require Dply-hosted Edge delivery.'));

            return;
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
    }

    public function render(): View
    {
        $repo = $this->edgeRepoConfigSection('snippets');

        return view('livewire.sites.edge.workspace.snippets', array_merge(
            EdgeSiteViewData::context($this->site, 'edge-snippets'),
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
