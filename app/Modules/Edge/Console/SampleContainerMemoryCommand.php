<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeQueueWorkers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sample each awake container app's memory high-water mark, to suggest a
 * smaller size when the app never needs what it has. Apps that are asleep
 * are skipped: the Worker says whether an instance runs without starting it.
 *
 *   php artisan dply:edge:sample-container-memory
 */
class SampleContainerMemoryCommand extends Command
{
    /** Samples kept per app (hourly: a week). */
    public const KEEP = 168;

    protected $signature = 'dply:edge:sample-container-memory';

    protected $description = 'Record awake container apps\' peak memory for right-size suggestions.';

    public function handle(): int
    {
        $sites = Site::query()
            ->where('meta->edge->runtime_mode', 'container')
            ->get()
            ->filter(fn (Site $site): bool => ! $site->isEdgePreview() && is_string($site->edgeLiveUrl()) && $site->edgeLiveUrl() !== '' && $site->isLaravelFrameworkDetected());

        foreach ($sites as $site) {
            $this->sample($site);
        }

        return self::SUCCESS;
    }

    public function sample(Site $site): void
    {
        $url = rtrim((string) $site->edgeLiveUrl(), '/');
        try {
            $instances = Http::timeout(15)->withHeaders(['x-dply-queue-token' => EdgeContainerDeployer::queueToken($site)])->get($url.'/_dply/instances')->json();
            $awake = collect(is_array($instances) ? $instances : [])->contains(fn ($i): bool => is_array($i) && in_array($i['status'] ?? '', ['running', 'healthy'], true));
            if (! $awake) {
                return; // asking would wake it
            }
            $body = EdgeQueueWorkers::command($site, 'resources');
        } catch (Throwable) {
            return;
        }
        $peak = $body['memory_peak_mb'] ?? null;
        if (! is_numeric($peak) || $peak <= 0) {
            return;
        }
        $meta = $site->edgeMeta()['memory'] ?? [];
        $type = EdgeContainerSettings::for($site)['instance_type'];
        // Peaks on another size say little about this one: start again.
        $earlier = ($meta['type'] ?? null) === $type ? (array) ($meta['samples'] ?? []) : [];
        $samples = array_slice([...$earlier, [now()->getTimestamp(), (float) $peak]], -self::KEEP);
        $site->mergeEdgeMeta(['memory' => [
            'samples' => $samples,
            'type' => $type, // what the peaks were measured on
        ]]);
        $site->save();
    }
}
