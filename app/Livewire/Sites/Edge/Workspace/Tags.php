<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\Edge\PublishesEdgeHostMap;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeTagVendors;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Third-party tag / script manager (customer-facing name; Zaraz-class capability).
 */
class Tags extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    public bool $enabled = false;

    public bool $consent_required = false;

    /** @var list<array{name: string, vendor: string, id: string, src: string, async: bool, purpose: string, path: string}> */
    public array $tools = [];

    /** Index into $tools open in the edit modal, or null. */
    public ?int $editingTool = null;

    /** The modal is showing the catalog picker (Add a tool). */
    public bool $pickingTool = false;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->loadFromSite();
    }

    /** Saved config → component state; drops unsaved edits. */
    private function loadFromSite(): void
    {
        $cfg = is_array($this->site->edgeMeta()['tags'] ?? null) ? $this->site->edgeMeta()['tags'] : [];
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $this->consent_required = (bool) ($cfg['consent_required'] ?? false);
        $this->tools = array_values(array_filter(array_map(
            static fn ($t) => is_array($t) ? EdgeTagVendors::normalize($t) : null,
            is_array($cfg['tools'] ?? null) ? $cfg['tools'] : [],
        )));
    }

    public function openPicker(): void
    {
        $this->loadFromSite();
        $this->resetErrorBag();
        $this->editingTool = null;
        $this->pickingTool = true;
        $this->dispatch('open-modal', 'edge-tag-tool');
    }

    public function editTool(int $index): void
    {
        $this->loadFromSite();
        abort_unless(isset($this->tools[$index]), 404);
        $this->resetErrorBag();
        $this->pickingTool = false;
        $this->editingTool = $index;
        $this->dispatch('open-modal', 'edge-tag-tool');
    }

    public function closeTool(): void
    {
        $this->loadFromSite();
        $this->editingTool = null;
        $this->pickingTool = false;
        $this->dispatch('close-modal', 'edge-tag-tool');
    }

    /** Modal Save: persists every tool (the one being edited included) and closes. */
    public function saveTool(): void
    {
        if ($this->save()) {
            $this->editingTool = null;
            $this->dispatch('close-modal', 'edge-tag-tool');
        }
    }

    public function removeEditingTool(): void
    {
        if ($this->editingTool === null) {
            return;
        }
        $this->removeTool($this->editingTool);
        $this->saveTool();
    }

    public function updatedEnabled(): void
    {
        $this->save();
    }

    public function updatedConsentRequired(): void
    {
        $this->save();
    }

    public function addTool(): void
    {
        $this->tools[] = ['name' => 'Custom script', 'vendor' => 'custom', 'id' => '', 'src' => '', 'async' => true, 'purpose' => 'analytics', 'path' => '/*'];
        $this->pickingTool = false;
        $this->editingTool = array_key_last($this->tools);
    }

    public function addVendor(string $vendor): void
    {
        $def = EdgeTagVendors::all()[$vendor] ?? null;
        if ($def === null) {
            return;
        }

        $this->tools[] = ['name' => $def['name'], 'vendor' => $vendor, 'id' => '', 'src' => '', 'async' => true, 'purpose' => $def['purpose'], 'path' => '/*'];
        $this->enabled = true;
        $this->pickingTool = false;
        $this->editingTool = array_key_last($this->tools);
    }

    public function removeTool(int $index): void
    {
        unset($this->tools[$index]);
        $this->tools = array_values($this->tools);
    }

    public function save(): bool
    {
        $this->authorize('update', $this->site);
        if (! $this->isManagedEdgeDelivery()) {
            $this->toastError(__('Tags require Dply-hosted Edge delivery.'));

            return false;
        }

        $this->validate([
            'tools.*.name' => ['required', 'string', 'max:64'],
            'tools.*.vendor' => ['required', Rule::in(['custom', ...array_keys(EdgeTagVendors::all())])],
            'tools.*.src' => ['exclude_unless:tools.*.vendor,custom', 'required', 'url', 'starts_with:https://', 'max:500'],
            'tools.*.id' => ['exclude_if:tools.*.vendor,custom', 'required', function (string $attribute, mixed $value, \Closure $fail): void {
                $vendor = (string) ($this->tools[(int) explode('.', $attribute)[1]]['vendor'] ?? '');
                if (! EdgeTagVendors::validId($vendor, in_array($vendor, ['ga4', 'gtm'], true) ? strtoupper(trim((string) $value)) : trim((string) $value))) {
                    $fail(__('Expected an ID like :example.', ['example' => EdgeTagVendors::all()[$vendor]['placeholder'] ?? '']));
                }
            }],
            'tools.*.purpose' => ['required', Rule::in(EdgeTagVendors::PURPOSES)],
            'tools.*.path' => ['nullable', 'string', 'max:200', 'regex:#^(\*|/.*)$#'],
        ]);

        // Consent helper needs the tag manager on — otherwise KV never receives
        // a tags block and window.__dplyTags never appears on the live site.
        if ($this->consent_required) {
            $this->enabled = true;
        }

        $this->site->mergeEdgeMeta([
            'tags' => [
                'enabled' => $this->enabled,
                'consent_required' => $this->consent_required,
                'tools' => array_values(array_filter(array_map(EdgeTagVendors::normalize(...), $this->tools))),
            ],
        ]);
        $this->site->save();
        $this->republishEdgeHostMap();
        $this->toastSuccess(__('Tags saved.'));

        return true;
    }

    public function render(): View
    {
        $repo = $this->edgeRepoConfigSection('tags');

        return view('livewire.sites.edge.workspace.tags', array_merge(
            EdgeSiteViewData::context($this->site, 'tags'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'managedDelivery' => $this->isManagedEdgeDelivery(),
                'vendors' => EdgeTagVendors::all(),
                'sourcePath' => $repo['source_path'],
                'repoTags' => $repo['section'],
            ],
        ));
    }
}
