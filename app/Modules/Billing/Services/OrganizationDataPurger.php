<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeDatabase;
use App\Models\EdgeQueue;
use App\Models\EdgeRealtimeApp;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
use App\Modules\Edge\Services\Realtime\EdgeRealtimeApps;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Throwable;

/**
 * Deletes a paused org's data once its keep_data_days are up (ruling
 * r-f17p5zgeh120cm5t): every site with its dply database and Valkey stores,
 * and the org's D1 databases, queues and Realtime apps. The org, its members and billing
 * record stay, so paying later starts from an empty workspace.
 *
 * TeardownEdgeSiteJob does not release dply databases, Valkey or D1, so
 * they are released here first. plan() lists everything without deleting.
 */
final class OrganizationDataPurger
{
    /**
     * @return list<string> One line per thing that would be deleted.
     */
    public function plan(Organization $organization): array
    {
        $lines = [];
        foreach ($this->sites($organization) as $site) {
            $lines[] = 'site '.$site->name.' ('.$site->id.')';
            $database = $site->edgeMeta()['database'] ?? null;
            if (is_array($database) && ($database['provider'] ?? '') === 'dply' && ($database['remote_id'] ?? '') !== '') {
                $lines[] = '  dply '.($database['engine'] ?? 'database').' '.$database['remote_id'];
            }
            foreach (EdgeContainerConnections::for($site) as $connection) {
                if (($connection['target'] ?? '') !== '') {
                    $lines[] = '  '.$connection['kind'].' '.$connection['target'];
                }
            }
        }
        foreach (EdgeDatabase::query()->where('organization_id', $organization->id)->get() as $d1) {
            $lines[] = 'd1 '.$d1->name;
        }
        foreach (EdgeQueue::query()->where('organization_id', $organization->id)->get() as $queue) {
            $lines[] = 'queue '.$queue->name;
        }
        foreach (EdgeRealtimeApp::query()->where('organization_id', $organization->id)->whereNull('site_id')->get() as $app) {
            $lines[] = 'realtime '.$app->id;
        }

        return $lines;
    }

    /** @return list<string> What could not be deleted (retried on the next run). */
    public function purge(Organization $organization): array
    {
        $failed = [];
        $attempt = function (string $what, callable $run) use (&$failed): void {
            try {
                $run();
            } catch (Throwable $e) {
                report($e);
                $failed[] = $what.': '.$e->getMessage();
            }
        };

        foreach ($this->sites($organization) as $site) {
            $database = $site->edgeMeta()['database'] ?? null;
            if (is_array($database) && ($database['provider'] ?? '') === 'dply' && ($database['remote_id'] ?? '') !== '') {
                $attempt('database '.$database['remote_id'], fn () => EdgeDplyDatabase::destroy((string) $database['remote_id'], EdgeDplyDatabase::regionOf($database)));
            }
            foreach (EdgeContainerConnections::for($site) as $connection) {
                $attempt($connection['kind'].' '.$connection['target'], fn () => EdgeContainerConnections::destroy((string) $connection['kind'], (string) $connection['target'], $organization));
            }
            $attempt('site '.$site->id, fn () => TeardownEdgeSiteJob::dispatchSync((string) $site->id));
        }
        // Realtime apps no site holds any more (the per-site pass above
        // deleted the attached ones): their relay KV record goes first.
        foreach (EdgeRealtimeApp::query()->where('organization_id', $organization->id)->get() as $app) {
            $attempt('realtime '.$app->id, fn () => app(EdgeRealtimeApps::class)->destroy($app));
        }

        $client = null;
        foreach (EdgeDatabase::query()->where('organization_id', $organization->id)->get() as $d1) {
            $attempt('d1 '.$d1->name, function () use (&$client, $d1): void {
                ($client ??= EdgeCloudflareClient::fromConfig())->deleteD1Database($d1->cloudflare_id);
                $d1->delete();
            });
        }
        foreach (EdgeQueue::query()->where('organization_id', $organization->id)->get() as $queue) {
            $attempt('queue '.$queue->name, function () use (&$client, $queue): void {
                ($client ??= EdgeCloudflareClient::fromConfig())->deleteQueue($queue->cloudflare_id);
                $queue->delete();
            });
        }

        return $failed;
    }

    /** @return iterable<Site> */
    private function sites(Organization $organization): iterable
    {
        return Site::query()->where('organization_id', $organization->id)->whereNotNull('edge_backend')->get();
    }
}
