<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Support\EdgeBuildDockerBootstrap;
use App\Modules\Edge\Support\EdgeLocalDevDiagnostics;
use App\Modules\Edge\Support\EdgePlatformCredentials;
use App\Modules\Edge\Support\EdgeTestingDomains;
use App\Modules\Edge\Support\FakeEdgeProvision;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Validate dply Edge production platform credentials and optional live probes.
 *
 *   dply:edge:doctor [--probe] [--json]
 */
class EdgeDoctorCommand extends Command
{
    private const PROBE_KV_KEY = '__dply_edge_doctor__';

    private const PROBE_R2_KEY = 'edge/__doctor__/probe.txt';

    protected $signature = 'dply:edge:doctor
                            {--probe : Write/read/delete probe objects in R2 and KV}
                            {--json : Output JSON}';

    protected $description = 'Validate Edge platform credentials (R2, KV, Cloudflare API).';

    public function handle(): int
    {
        $report = $this->compileReport();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ($report['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $this->renderHuman($report);

        return ($report['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function compileReport(): array
    {
        $summary = EdgePlatformCredentials::summary();
        $missing = EdgePlatformCredentials::missing();
        $checks = [];

        if (FakeEdgeProvision::enabled()) {
            $checks = EdgeLocalDevDiagnostics::checks();
            $failed = array_filter($checks, static fn (array $check): bool => ! $check['ok']);

            return [
                'ok' => true,
                'mode' => 'fake',
                'message' => $failed === []
                    ? 'DPLY_FAKE_EDGE is active — local hostname checks passed.'
                    : 'DPLY_FAKE_EDGE is active — local DNS may need setup (see checks below).',
                'summary' => $summary,
                'missing' => [],
                'checks' => $checks,
            ];
        }

        if ($missing !== []) {
            return [
                'ok' => false,
                'mode' => 'production',
                'message' => 'Missing required Edge platform credentials.',
                'summary' => $summary,
                'missing' => $missing,
                'checks' => [],
            ];
        }

        $checks[] = $this->checkCloudflareToken();
        $checks[] = $this->checkUsageAnalyticsCollection();
        $checks[] = $this->checkBuildDocker();
        $checks = array_merge($checks, $this->checkEdgeDeliveryZone());

        if ($this->option('probe')) {
            $checks[] = $this->checkKvProbe();
            $checks[] = $this->checkR2Probe();
        }

        $failed = array_values(array_filter($checks, fn (array $check): bool => ($check['ok'] ?? false) === false));

        return [
            'ok' => $failed === [],
            'mode' => 'production',
            'message' => $failed === [] ? 'Edge platform credentials look good.' : 'One or more checks failed.',
            'summary' => $summary,
            'missing' => [],
            'checks' => $checks,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function checkEdgeDeliveryZone(): array
    {
        $checks = [];
        $nestedRoutes = EdgePlatformCredentials::nestedOnDplyWorkerRoutes();
        if ($nestedRoutes !== []) {
            $checks[] = [
                'name' => 'edge_worker_routes',
                'ok' => false,
                'detail' => 'Remove nested on-dply worker routes (use *.on-dply.site/* on its own zone): '
                    .implode(', ', $nestedRoutes),
            ];
        }

        $deliveryApex = EdgeTestingDomains::defaultApex();
        $workerZone = strtolower(trim((string) config('edge.cloudflare.worker_zone_name')));

        if ($workerZone !== '' && $workerZone !== strtolower($deliveryApex)) {
            $checks[] = [
                'name' => 'edge_zone_alignment',
                'ok' => false,
                'detail' => 'DPLY_EDGE_CF_ZONE_NAME ('.$workerZone.') should match Edge delivery apex ('.$deliveryApex.').',
            ];
        }

        if ($workerZone === '') {
            return $checks;
        }

        try {
            $zoneId = EdgeCloudflareClient::fromConfig()->activeZoneId($workerZone);
            $checks[] = [
                'name' => 'edge_delivery_zone_on_cloudflare',
                'ok' => $zoneId !== null,
                'detail' => $zoneId !== null
                    ? 'Zone '.$workerZone.' is active on Cloudflare.'
                    : 'Zone '.$workerZone.' is not on Cloudflare yet — add it and point NS before Edge hostnames work.',
            ];
        } catch (\Throwable $e) {
            $checks[] = [
                'name' => 'edge_delivery_zone_on_cloudflare',
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }

        return $checks;
    }

    /**
     * Docker must be reachable on the host that drains Edge build jobs.
     *
     * @return array<string, mixed>
     */
    private function checkBuildDocker(): array
    {
        if (app()->runningUnitTests()) {
            return [
                'name' => 'edge_build_docker',
                'ok' => true,
                'detail' => 'Skipped under PHPUnit — probe on the real Horizon / build host.',
            ];
        }

        if (EdgeBuildDockerBootstrap::daemonReachable()) {
            return [
                'name' => 'edge_build_docker',
                'ok' => true,
                'detail' => 'Docker daemon reachable ('.EdgeBuildDockerBootstrap::probeDetail().').',
            ];
        }

        return [
            'name' => 'edge_build_docker',
            'ok' => false,
            'detail' => 'Docker daemon not reachable from this host ('.EdgeBuildDockerBootstrap::probeDetail()
                .'). Edge builds fail until you run (as root on this worker): '
                .'sudo php artisan dply:edge:ensure-build-docker --user='.EdgeBuildDockerBootstrap::queueUser(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkUsageAnalyticsCollection(): array
    {
        $client = EdgeCloudflareClient::fromConfig();
        $canCollect = $client->canCollectAnalytics();

        return [
            'name' => 'edge_usage_analytics',
            'ok' => $canCollect,
            'detail' => $canCollect
                ? 'Cloudflare Analytics credentials configured. Traffic stats are collected daily at 02:00 UTC via dply:edge:collect-usage (requires Laravel scheduler / cron).'
                : 'Set DPLY_EDGE_CF_ACCOUNT_ID, DPLY_EDGE_CF_API_TOKEN, and DPLY_EDGE_CF_ZONE_NAME (Analytics Read) so dply:edge:collect-usage can pull visitor request and bandwidth stats.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkCloudflareToken(): array
    {
        try {
            $result = EdgeCloudflareClient::fromConfig()->verifyToken();
            $status = is_string($result['status'] ?? null) ? $result['status'] : 'unknown';

            return [
                'name' => 'cloudflare_api_token',
                'ok' => $status === 'active',
                'detail' => 'Token status: '.$status,
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'cloudflare_api_token',
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkKvProbe(): array
    {
        $accountId = (string) config('edge.cloudflare.account_id');
        $namespaceId = (string) config('edge.cloudflare.kv_namespace_id');
        $token = (string) config('edge.cloudflare.api_token');
        $url = 'https://api.cloudflare.com/client/v4/accounts/'.$accountId
            .'/storage/kv/namespaces/'.$namespaceId.'/values/'.rawurlencode(self::PROBE_KV_KEY);

        try {
            Http::withToken($token)
                ->withBody('{"probe":true}', 'application/json')
                ->put($url)
                ->throw();

            Http::withToken($token)->get($url)->throw();

            Http::withToken($token)->delete($url)->throw();

            return [
                'name' => 'kv_write_read_delete',
                'ok' => true,
                'detail' => 'KV namespace accepts host-map writes.',
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'kv_write_read_delete',
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkR2Probe(): array
    {
        try {
            $disk = Storage::disk((string) config('edge.disk.name', 'edge_r2'));
            $disk->put(self::PROBE_R2_KEY, 'dply-edge-doctor', ['visibility' => 'private']);
            $exists = $disk->exists(self::PROBE_R2_KEY);
            $disk->delete(self::PROBE_R2_KEY);

            return [
                'name' => 'r2_write_read_delete',
                'ok' => $exists,
                'detail' => $exists ? 'R2 bucket accepts artifact uploads.' : 'Probe object missing after upload.',
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'r2_write_read_delete',
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderHuman(array $report): void
    {
        $this->info((string) ($report['message'] ?? 'Edge doctor'));
        $this->newLine();

        if (($report['mode'] ?? '') === 'fake') {
            $this->warn('Fake edge mode is enabled. Set DPLY_FAKE_EDGE=false in production.');
            $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
            if ($checks !== []) {
                $this->newLine();
                $this->table(
                    ['Check', 'OK', 'Detail'],
                    array_map(fn (array $check): array => [
                        (string) ($check['name'] ?? ''),
                        ($check['ok'] ?? false) ? 'yes' : 'no',
                        (string) ($check['detail'] ?? ''),
                    ], $checks),
                );
            }
            $this->newLine();
            $this->line('See: docs/edge-local-development.md');

            return;
        }

        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $this->table(['Setting', 'Value'], collect($summary)->map(fn ($v, $k) => [$k, $v])->values()->all());

        $missing = is_array($report['missing'] ?? null) ? $report['missing'] : [];
        if ($missing !== []) {
            $this->newLine();
            $this->error('Missing credentials:');
            foreach ($missing as $item) {
                $this->line('  - '.$item);
            }
            $this->newLine();
            $this->line('Run: php artisan dply:edge:infra:bootstrap --help');
            $this->line('See: docs/edge-production-setup.md');

            return;
        }

        $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
        if ($checks !== []) {
            $this->newLine();
            $this->table(
                ['Check', 'OK', 'Detail'],
                array_map(fn (array $check): array => [
                    (string) ($check['name'] ?? ''),
                    ($check['ok'] ?? false) ? 'yes' : 'no',
                    (string) ($check['detail'] ?? ''),
                ], $checks),
            );
        }

        if (! $this->option('probe') && ($report['ok'] ?? false)) {
            $this->newLine();
            $this->line('Tip: re-run with --probe to test live R2 + KV writes.');
        }
    }
}
