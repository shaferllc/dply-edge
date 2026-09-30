<?php

declare(strict_types=1);

namespace App\Modules\Edge\Livewire;

use App\Models\Organization;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * Projects → Storage: an organization's object storage buckets (R2, named
 * under EdgeContainerConnections::ownedPrefix). Lists them from Cloudflare,
 * shows which apps use each, and creates or deletes one. Apps attach a
 * bucket from their Resources map ("Attach existing").
 */
#[Layout('layouts.app')]
class Buckets extends Component
{
    public string $name = '';

    public string $location = '';

    public function create(): void
    {
        $org = $this->organization();
        $this->authorize('update', $org);
        // R2 names are 3–63 characters; the org prefix takes 31.
        $this->validate(
            ['name' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,30}$/'], 'location' => ['in:'.implode(',', array_keys(EdgeContainerConnections::R2_LOCATION_HINTS))]],
            ['name.regex' => __('Use 2 to 31 lowercase letters, numbers and dashes.')],
        );
        if (collect($this->buckets($org))->contains('label', $this->name)) {
            $this->addError('name', __('You already have a bucket with that name.'));

            return;
        }

        try {
            EdgeContainerConnections::provision('object_storage', $this->name, $org, ['location_hint' => $this->location ?: null]);
        } catch (Throwable $e) {
            $this->addError('name', $e->getMessage());

            return;
        }
        audit_log($org, auth()->user(), 'bucket.created', null, null, ['name' => $this->name]);
        Cache::forget($this->cacheKey($org));
        session()->flash('status', __('Created :name. Attach it to an app from that app’s Resources map.', ['name' => $this->name]));
        $this->reset('name', 'location');
    }

    public function delete(string $bucket): void
    {
        $org = $this->organization();
        $this->authorize('update', $org);
        if (($this->boundTo($org)[$bucket] ?? []) !== []) {
            $this->addError('bucket', __('Detach this bucket from every app first.'));

            return;
        }

        try {
            if (! EdgeContainerConnections::destroy('object_storage', $bucket, $org)) {
                return;
            }
        } catch (Throwable $e) {
            $this->addError('bucket', str_contains(strtolower($e->getMessage()), 'not empty')
                ? __('This bucket still has files. Empty it from an app’s Object storage sheet, then delete it.')
                : $e->getMessage());

            return;
        }
        audit_log($org, auth()->user(), 'bucket.deleted', null, null, ['bucket' => $bucket]);
        Cache::forget($this->cacheKey($org));
    }

    public function render(): View
    {
        $org = $this->organization();

        return view('livewire.edge.buckets', [
            'buckets' => $this->buckets($org),
            'boundTo' => $this->boundTo($org),
            'locations' => EdgeContainerConnections::R2_LOCATION_HINTS,
        ]);
    }

    /** @return list<array{id: string, label: string}> */
    private function buckets(Organization $org): array
    {
        return Cache::remember($this->cacheKey($org), 60, fn (): array => EdgeContainerConnections::catalog('object_storage', $org));
    }

    /**
     * Which apps use each bucket, and as which disk.
     *
     * @return array<string, list<array{site: string, url: string, disk: string}>>
     */
    private function boundTo(Organization $org): array
    {
        $bound = [];
        foreach ($org->sites()->whereNotNull('edge_backend')->orderBy('name')->get(['id', 'name', 'meta', 'organization_id']) as $site) {
            foreach (EdgeContainerConnections::for($site) as $connection) {
                if ($connection['kind'] === 'object_storage') {
                    $bound[$connection['target']][] = ['site' => (string) $site->name, 'url' => route('sites.show', $site), 'disk' => strtolower($connection['name'])];
                }
            }
        }

        return $bound;
    }

    private function cacheKey(Organization $org): string
    {
        return 'edge-buckets:'.$org->id;
    }

    private function organization(): Organization
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        return $org;
    }
}
