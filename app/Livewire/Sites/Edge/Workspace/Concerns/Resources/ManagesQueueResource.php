<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace\Concerns\Resources;

use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeQueue;
use App\Modules\Billing\Services\EdgeDataUsageCost;
use App\Modules\Edge\Services\EdgeQueueConsumers;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Facades\DB;

/**
 * Resources sheet: Queue (Cloudflare Queues). Mixed into
 * {@see Resources}; the open connection
 * is $this->openResourceConnection() (set by openResource()).
 *
 * Every call re-reads the connection from the site and checks this
 * organization owns the queue: dply's Cloudflare account holds every
 * organization's queues, and nothing the browser sends picks the queue id.
 */
trait ManagesQueueResource
{
    /** Cloudflare's per-message limit. */
    private const QUEUE_MESSAGE_MAX_BYTES = 128 * 1024;

    /** The host queueLoad() last loaded, so opening another queue starts clean. */
    public string $queueLoadedHost = '';

    /**
     * What the sheet shows, read on opening it (never during render).
     *
     * @var array{backlog: ?int, consumers: ?list<array{script: string, type: string, batch_size: ?int, max_retries: ?int, max_wait_ms: ?int, max_concurrency: ?int, dead_letter_queue: string}>, owner: ?string, owner_is_this: bool, operations: int}|null
     */
    public ?array $queueDetail = null;

    public ?string $queueError = null;

    public string $queueTestBody = "{\n    \"dply_test\": true\n}";

    public ?string $queueTestResult = null;

    public bool $queueTestOk = false;

    public function queueLoad(): void
    {
        $this->authorize('view', $this->site);
        $connection = $this->queueOwnedConnection();
        if ($connection === null) {
            $this->queueError = __('This queue belongs to another organization, or is no longer attached.');

            return;
        }
        if ($this->queueLoadedHost !== $connection['host']) {
            $this->reset('queueDetail', 'queueError', 'queueTestBody', 'queueTestResult', 'queueTestOk');
            $this->queueLoadedHost = $connection['host'];
        }

        $owner = EdgeQueueConsumers::owner($this->site->organization, $connection['target']);
        $detail = ['backlog' => null, 'consumers' => null, 'owner' => $owner?->name, 'owner_is_this' => $owner?->is($this->site) === true, 'operations' => (int) $this->queueMonthOperations($connection['target'])];
        $this->queueError = null;

        try {
            $id = EdgeContainerConnections::queueId($connection['target'], $this->site->organization);
            if ($id === '') {
                $this->queueError = __('Cloudflare does not list this queue. It may have been deleted.');
                $this->queueDetail = $detail;

                return;
            }
            $client = EdgeCloudflareClient::fromConfig();
        } catch (\Throwable) {
            $this->queueError = __('Cloudflare did not answer. Try again in a moment.');
            $this->queueDetail = $detail;

            return;
        }

        try {
            $detail['consumers'] = array_values(array_map(static fn (array $c): array => [
                'script' => (string) ($c['script'] ?? $c['script_name'] ?? $c['service'] ?? ''),
                'type' => (string) ($c['type'] ?? 'worker'),
                'batch_size' => isset($c['settings']['batch_size']) ? (int) $c['settings']['batch_size'] : null,
                'max_retries' => isset($c['settings']['max_retries']) ? (int) $c['settings']['max_retries'] : null,
                'max_wait_ms' => isset($c['settings']['max_wait_time_ms']) ? (int) $c['settings']['max_wait_time_ms'] : null,
                'max_concurrency' => isset($c['settings']['max_concurrency']) ? (int) $c['settings']['max_concurrency'] : null,
                'dead_letter_queue' => (string) ($c['dead_letter_queue'] ?? ''),
            ], array_filter((array) ($client->getQueue($id)['consumers'] ?? []), 'is_array')));
        } catch (\Throwable) {
            $this->queueError = __('Could not read the queue’s consumers. Try again in a moment.');
        }

        try {
            $detail['backlog'] = $client->queueBacklogs([$id])[$id] ?? 0;
        } catch (\Throwable) {
            // Shown as unknown; the rest of the sheet still works.
        }

        $this->queueDetail = $detail;
    }

    public function queueSendTest(): void
    {
        $this->authorize('update', $this->site);
        $this->queueTestResult = null;
        $this->queueTestOk = false;
        $connection = $this->queueOwnedConnection();
        if ($connection === null) {
            $this->queueTestResult = __('This queue belongs to another organization, or is no longer attached.');

            return;
        }
        if (strlen($this->queueTestBody) > self::QUEUE_MESSAGE_MAX_BYTES) {
            $this->queueTestResult = __('Keep the message under 128 KB.');

            return;
        }
        try {
            $body = json_decode($this->queueTestBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->queueTestResult = __('That is not valid JSON.');

            return;
        }

        try {
            $id = EdgeContainerConnections::queueId($connection['target'], $this->site->organization);
            if ($id === '') {
                $this->queueTestResult = __('Cloudflare does not list this queue. It may have been deleted.');

                return;
            }
            EdgeCloudflareClient::fromConfig()->sendQueueMessage($id, $body);
        } catch (\Throwable) {
            $this->queueTestResult = __('Cloudflare did not take the message. Try again in a moment.');

            return;
        }

        $this->queueTestOk = true;
        $this->queueTestResult = __('Sent. The app that runs this queue’s jobs gets it in its next batch.');
    }

    /** This queue's cost so far this month (the card and the sheet), or null to use the organization's. */
    public function queueCostCents(array $connection): ?int
    {
        $operations = $this->queueMonthOperations((string) ($connection['target'] ?? ''));

        return $operations === null ? null : app(EdgeDataUsageCost::class)->cents(0, 0, 0, $operations);
    }

    /** Billable operations so far this month, or null when the queue is not this organization's. */
    private function queueMonthOperations(string $target): ?int
    {
        $id = $target === '' ? '' : (string) EdgeQueue::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('cloudflare_name', $target)
            ->value('cloudflare_id');
        if ($id === '') {
            return null;
        }

        return (int) DB::table('edge_queue_usage')
            ->where('queue_id', $id)
            ->where('organization_id', $this->site->organization_id)
            ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->sum('operations');
    }

    /** @return array{kind: string, name: string, host: string, target: string, asleep: bool, plan: string, read_regions: int}|null */
    private function queueOwnedConnection(): ?array
    {
        $connection = $this->openResourceConnection();
        if ($connection === null || $connection['kind'] !== 'queue' || $this->site->organization === null
            || ! EdgeContainerConnections::owns('queue', $connection['target'], $this->site->organization)) {
            return null;
        }

        return $connection;
    }
}
