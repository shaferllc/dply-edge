<?php

declare(strict_types=1);

namespace App\Modules\Edge\Livewire;

use App\Models\Site;
use App\Modules\Edge\Support\EdgeResourceIndex;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Projects → Resources: every app in the organization and everything it
 * uses, in one list (T-039, ruling r-5w7h5d0b902aeq0n). Each row links to
 * where it is managed already: the app's workspace, its Resources map, its
 * Console, or the D1 and Queues pages. Read-only; nothing here wakes an app.
 */
#[Layout('layouts.app')]
class ResourceIndex extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $kind = '';

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);
        $rows = EdgeResourceIndex::rows((string) $org->id);
        $sites = Site::query()->where('organization_id', $org->id)->get(['id', 'server_id'])->keyBy('id');
        $needle = Str::lower(trim($this->search));

        $groups = [];
        foreach (EdgeResourceIndex::KINDS as $key => $label) {
            $inGroup = array_values(array_filter($rows, fn (array $row): bool => $row['group'] === $key
                && ($this->kind === '' || $this->kind === $key)
                && ($needle === '' || str_contains(Str::lower($row['name'].' '.implode(' ', $row['apps']).' '.$row['id']), $needle))));
            if ($inGroup !== []) {
                $groups[] = ['key' => $key, 'label' => $label, 'rows' => array_map(fn (array $row): array => $row + ['links' => $this->links($row, $sites)], $inGroup)];
            }
        }

        return view('livewire.edge.resource-index', [
            'groups' => $groups,
            'trouble' => count(array_filter($rows, fn (array $row): bool => $row['problem'] !== null)),
        ]);
    }

    /**
     * Where a row is managed.
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<string, Site>  $sites
     * @return list<array{label: string, href: string}>
     */
    private function links(array $row, $sites): array
    {
        $site = $row['siteId'] !== null ? $sites->get($row['siteId']) : null;
        $section = fn (string $section): ?string => $site?->server_id !== null ? route('sites.show', ['server' => $site->server_id, 'site' => $site->id, 'section' => $section]) : null;

        return array_values(array_filter(match ($row['group']) {
            'app' => [
                ['label' => __('Open'), 'href' => $section('general')],
                $row['engine'] === 'container' ? ['label' => __('Console'), 'href' => $section('console')] : null,
            ],
            'database', 'redis', 'other' => [['label' => __('Manage'), 'href' => $section('general')]],
            'd1' => [['label' => __('Manage'), 'href' => route('edge.databases')]],
            'queue' => [['label' => __('Manage'), 'href' => route('edge.queues')]],
            default => [],
        }, fn ($link): bool => is_array($link) && $link['href'] !== null));
    }
}
