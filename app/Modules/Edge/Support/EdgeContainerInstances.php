<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A container app's instances right now: the site Worker's per-instance
 * state (reading it never starts one), Cloudflare's health counts for the
 * container application, and the queue workers. Each read is best effort
 * and the whole snapshot is cached briefly per site.
 */
final class EdgeContainerInstances
{
    /** Statuses that count as up (SampleContainerMemoryCommand's "awake"). */
    public const RUNNING = ['running', 'healthy'];

    /**
     * @return array{
     *     instances: ?list<array{name: string, status: string, since: ?int, lastActivity: ?int}>, running: int, error: ?string,
     *     health: ?array<string, int>, version: ?int,
     *     workers: ?list<array<string, mixed>>,
     * }
     */
    public static function snapshot(Site $site): array
    {
        return Cache::remember('edge-container-instances:'.$site->id, 15, static fn (): array => self::read($site));
    }

    /** @return array<string, mixed> */
    private static function read(Site $site): array
    {
        $out = ['instances' => null, 'running' => 0, 'error' => null, 'health' => null, 'version' => null, 'workers' => null];

        try {
            $rows = Http::timeout(10)->withHeaders(['x-dply-queue-token' => EdgeContainerDeployer::queueToken($site)])
                ->get(rtrim((string) $site->edgeLiveUrl(), '/').'/_dply/instances')->throw()->json();
            $out['instances'] = array_values(array_map(static fn (array $row): array => [
                'name' => (string) ($row['name'] ?? ''),
                'status' => (string) ($row['status'] ?? 'unknown'),
                'since' => isset($row['lastChange']) ? intdiv((int) $row['lastChange'], 1000) : null,
                'lastActivity' => is_numeric($row['lastActivity'] ?? null) ? intdiv((int) $row['lastActivity'], 1000) : null,
            ], array_filter(is_array($rows) ? $rows : [], 'is_array')));
            $out['running'] = count(array_filter($out['instances'], static fn (array $i): bool => in_array($i['status'], self::RUNNING, true)));
        } catch (Throwable) {
            $out['error'] = __('The app did not answer, so live instance state is not available. Try again in a moment.');
        }

        try {
            $client = EdgeCloudflareClient::fromConfig();
            $script = EdgeContainerDeployer::scriptName($site);
            foreach ($client->listContainerApplications() as $application) {
                if ($application['id'] === '' || ! str_starts_with($application['name'], $script)) {
                    continue;
                }
                $detail = $client->containerApplication($application['id']);
                foreach ((array) ($detail['health']['instances'] ?? []) as $key => $count) {
                    $out['health'][$key] = ($out['health'][$key] ?? 0) + (int) $count;
                }
                $out['version'] = max((int) $out['version'], (int) ($detail['version'] ?? 0)) ?: null;
            }
        } catch (Throwable) {
            // A token without Containers Read still shows the Worker's view.
        }

        if (EdgeQueueWorkers::runningInstances($site) > 0) {
            try {
                $out['workers'] = EdgeQueueWorkers::status($site);
            } catch (Throwable) {
                // Worker status is also on the workers sheet.
            }
        }

        return $out;
    }
}
