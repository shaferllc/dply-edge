<?php

declare(strict_types=1);

namespace App\Modules\Edge\Livewire;

use App\Models\EdgeQueue;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * Projects → Queues: an organization's Cloudflare Queues. Create, watch the
 * backlog, send a test message, and attach a queue to a project as a binding
 * (container apps consume it through dply/laravel or dply-rails).
 */
#[Layout('layouts.app')]
class Queues extends Component
{
    public string $name = '';

    public string $attachQueue = '';

    public string $attachSite = '';

    public string $bindingName = 'JOBS';

    public function create(): void
    {
        $org = $this->organization();
        $this->authorize('update', $org);
        $this->validate(['name' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/']], ['name.regex' => __('Use lowercase letters, numbers and dashes.')]);

        $limit = $org->tierAllowances()['queues'] ?? null;
        if ($limit !== null && EdgeQueue::query()->where('organization_id', $org->id)->count() >= $limit) {
            $this->addError('name', __('Your :plan plan includes :count queues. Upgrade on the billing page for more.', ['plan' => $org->planTierLabel(), 'count' => $limit]));

            return;
        }
        if (EdgeQueue::query()->where('organization_id', $org->id)->where('name', $this->name)->exists()) {
            $this->addError('name', __('You already have a queue with that name.'));

            return;
        }

        $cloudflareName = EdgeQueue::cloudflareName($org, $this->name);
        try {
            $created = $this->client()->createQueue($cloudflareName);
        } catch (Throwable $e) {
            $this->addError('name', __('Dply Edge: :error', ['error' => $e->getMessage()]));

            return;
        }

        $queue = EdgeQueue::query()->create([
            'organization_id' => $org->id,
            'name' => $this->name,
            'cloudflare_id' => (string) ($created['queue_id'] ?? $created['id'] ?? ''),
            'cloudflare_name' => $cloudflareName,
            'created_by' => auth()->id(),
        ]);
        audit_log($org, auth()->user(), 'queue.created', $queue, null, ['name' => $queue->name]);
        $this->reset('name');
    }

    public function sendTest(string $id): void
    {
        $queue = $this->queue($id);
        $this->authorize('update', $queue->organization);

        try {
            $this->client()->sendQueueMessage($queue->cloudflare_id, ['dply_test' => true, 'sent_at' => now()->toIso8601String()]);
            session()->flash('status', __('Test message sent to :queue.', ['queue' => $queue->name]));
        } catch (Throwable $e) {
            $this->addError('queue', __('Dply Edge: :error', ['error' => $e->getMessage()]));
        }
    }

    public function attach(): void
    {
        $this->validate([
            'attachQueue' => ['required', 'string'],
            'attachSite' => ['required', 'string'],
            'bindingName' => ['required', 'regex:/^[A-Z][A-Z0-9_]{0,63}$/'],
        ], ['bindingName.regex' => __('Binding names are UPPER_SNAKE_CASE.')]);

        $queue = $this->queue($this->attachQueue);
        $site = Site::query()->where('organization_id', $queue->organization_id)->findOrFail($this->attachSite);
        $this->authorize('update', $site);

        $error = EdgeContainerConnections::attach($site, 'queue', $this->bindingName, (string) $queue->cloudflare_name);
        if ($error !== null) {
            $this->addError('bindingName', $error);

            return;
        }

        session()->flash('status', __(':queue is bound to :site as :binding. Redeploy the project to use it.', ['queue' => $queue->name, 'site' => $site->name, 'binding' => $this->bindingName]));
    }

    public function delete(string $id): void
    {
        $queue = $this->queue($id);
        $this->authorize('update', $queue->organization);

        try {
            $this->client()->deleteQueue($queue->cloudflare_id);
        } catch (Throwable $e) {
            $this->addError('queue', __('Dply Edge: :error', ['error' => $e->getMessage()]));

            return;
        }

        audit_log($queue->organization, auth()->user(), 'queue.deleted', $queue, null, ['name' => $queue->name]);
        $queue->delete();
    }

    public function render(): View
    {
        $org = $this->organization();
        $queues = EdgeQueue::query()->where('organization_id', $org->id)->orderBy('name')->get();

        $backlogs = [];
        if ($queues->isNotEmpty()) {
            try {
                $backlogs = Cache::remember('queue-backlogs:'.$org->id, 30, fn () => $this->client()->queueBacklogs($queues->pluck('cloudflare_id')->all()));
            } catch (Throwable) {
                $backlogs = [];
            }
        }

        // Which projects each queue is bound to.
        $boundTo = [];
        $sites = $org->sites()->whereNotNull('edge_backend')->orderBy('name')->get(['id', 'name', 'meta']);
        foreach ($sites as $site) {
            foreach (EdgeContainerConnections::for($site) as $connection) {
                if ($connection['kind'] === 'queue') {
                    $boundTo[$connection['target']][] = $site->name.' ('.$connection['name'].')';
                }
            }
        }

        return view('livewire.edge.queues', [
            'queues' => $queues,
            'backlogs' => $backlogs,
            'boundTo' => $boundTo,
            'sites' => $sites,
            'limit' => $org->tierAllowances()['queues'] ?? null,
        ]);
    }

    private function queue(string $id): EdgeQueue
    {
        return EdgeQueue::query()->where('organization_id', $this->organization()->id)->findOrFail($id);
    }

    private function organization(): Organization
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        return $org;
    }

    private function client(): EdgeCloudflareClient
    {
        return EdgeCloudflareClient::fromConfig();
    }
}
