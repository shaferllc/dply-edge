<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Support\EdgeTestingDomains;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Console\Command;
use RuntimeException;

class EnsureEdgeLogpushCommand extends Command
{
    protected $signature = 'dply:edge:ensure-logpush
                            {--dry-run : Print destination URL without creating jobs}';

    protected $description = 'Ensure Cloudflare Logpush http_requests jobs target the Dply ingest endpoint.';

    public function handle(EdgeCloudflareClient $client): int
    {
        if (! filter_var((string) config('edge.logpush.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->warn('Logpush is disabled. Set DPLY_EDGE_LOGPUSH_ENABLED=true and DPLY_EDGE_LOGPUSH_SECRET.');

            return self::FAILURE;
        }

        $destinationUrl = trim((string) config('edge.logpush.destination_url', ''));
        if ($destinationUrl === '') {
            $this->error('Missing edge.logpush.destination_url (DPLY_EDGE_LOGPUSH_DESTINATION_URL).');

            return self::FAILURE;
        }

        $secret = trim((string) config('edge.logpush.secret', ''));
        $destinationConf = 'uri='.rawurlencode($destinationUrl);
        if ($secret !== '') {
            $destinationConf .= '|header_Authorization=Bearer%20'.rawurlencode($secret);
        }

        if (! $client->canCollectAnalytics()) {
            $this->error('Cloudflare analytics credentials are required to resolve zones for Logpush.');

            return self::FAILURE;
        }

        $zones = array_values(array_unique(array_filter(array_merge(
            [(string) config('edge.cloudflare.worker_zone_name')],
            EdgeTestingDomains::workerRouteZones(),
        ))));

        if ($zones === []) {
            $this->error('No Edge analytics zones configured.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            foreach ($zones as $zone) {
                $this->line("Would ensure Logpush job for zone {$zone} → {$destinationUrl}");
            }

            return self::SUCCESS;
        }

        foreach ($zones as $zoneName) {
            $zoneId = $client->activeZoneId($zoneName);
            if ($zoneId === null) {
                $this->warn("Skipping {$zoneName}: zone not active on Cloudflare.");

                continue;
            }

            try {
                $job = $client->ensureLogpushJob($zoneId, $destinationConf, 'http_requests');
                $this->info("Logpush job {$job['id']} enabled for {$zoneName}.");
            } catch (RuntimeException $e) {
                $this->error("Failed for {$zoneName}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
