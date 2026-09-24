<?php

declare(strict_types=1);

namespace App\Modules\Providers\Neon;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Platform Neon account. Creates one Postgres project per app. Compute
 * suspends when nothing is connected. The password is only in the create
 * response.
 *
 * Called from EdgeAppDatabase.
 * API: POST /projects, DELETE /projects/{id},
 * GET /consumption_history/v2/projects.
 */
final class NeonClient
{
    /**
     * Locations a project can be created in. The id is fixed after create.
     *
     * @var array<string, string>
     */
    public const REGIONS = [
        'aws-us-east-1' => 'US East (N. Virginia)',
        'aws-us-east-2' => 'US East (Ohio)',
        'aws-us-west-2' => 'US West (Oregon)',
        'aws-eu-central-1' => 'Europe (Frankfurt)',
        'aws-eu-west-2' => 'Europe (London)',
        'aws-ap-southeast-1' => 'Asia Pacific (Singapore)',
        'aws-ap-southeast-2' => 'Asia Pacific (Sydney)',
        'aws-sa-east-1' => 'South America (São Paulo)',
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $region,
        private readonly string $organization = '',
        private readonly float $minCu = 0.25,
        private readonly float $maxCu = 2,
        private readonly int $suspendSeconds = 300,
    ) {}

    public static function configured(): bool
    {
        return (string) config('edge.neon.api_key') !== '';
    }

    public static function fromConfig(): self
    {
        $apiKey = (string) config('edge.neon.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('Postgres cannot be started from here yet.');
        }

        return new self(
            $apiKey,
            (string) config('edge.neon.region', 'aws-us-east-1'),
            (string) config('edge.neon.organization', ''),
            (float) config('edge.neon.min_cu', 0.25),
            (float) config('edge.neon.max_cu', 2),
            (int) config('edge.neon.suspend_seconds', 300),
        );
    }

    /**
     * @return array{id: string, endpoint_id: string, host: string, port: string, database: string, username: string, password: string}
     */
    public function create(string $name, ?float $minCu = null, ?float $maxCu = null, ?int $suspendSeconds = null, ?string $region = null): array
    {
        $project = [
            'name' => $name,
            'pg_version' => 17,
            'history_retention_seconds' => 86400,
            'region_id' => $this->resolveRegion($region),
            'default_endpoint_settings' => [
                'autoscaling_limit_min_cu' => $minCu ?? $this->minCu,
                'autoscaling_limit_max_cu' => $maxCu ?? $this->maxCu,
                'suspend_timeout_seconds' => $suspendSeconds ?? $this->suspendSeconds,
            ],
        ];
        $response = $this->http()->post('/projects', ['project' => $project]);
        if (! $response->successful()) {
            throw new RuntimeException($this->failure($response->json(), 'Postgres could not be started.'));
        }

        $body = $response->json();
        $id = (string) data_get($body, 'project.id', '');
        $parameters = data_get($body, 'connection_uris.0.connection_parameters', []);
        $host = is_array($parameters) ? (string) ($parameters['host'] ?? '') : '';
        $database = is_array($parameters) ? (string) ($parameters['database'] ?? '') : '';
        $username = is_array($parameters) ? (string) ($parameters['role'] ?? '') : '';
        $password = is_array($parameters) ? (string) ($parameters['password'] ?? '') : '';
        if ($id === '' || $host === '' || $database === '' || $username === '' || $password === '') {
            if ($id !== '') {
                $this->delete($id);
            }
            throw new RuntimeException('Postgres started, but the address did not come back.');
        }

        return [
            'id' => $id,
            'endpoint_id' => (string) data_get($body, 'endpoints.0.id', ''),
            'host' => $host,
            'port' => '5432',
            'database' => $database,
            'username' => $username,
            'password' => $password,
        ];
    }

    public function configure(string $projectId, float $minCu, float $maxCu, int $suspendSeconds, string $endpointId = ''): void
    {
        if ($endpointId === '') {
            $listed = $this->http()->get('/projects/'.$projectId.'/endpoints');
            if (! $listed->successful()) {
                throw new RuntimeException($this->failure($listed->json(), 'Postgres size could not be changed.'));
            }
            foreach ((array) $listed->json('endpoints') as $endpoint) {
                if (! is_array($endpoint)) {
                    continue;
                }
                $candidate = (string) ($endpoint['id'] ?? '');
                if ($candidate === '') {
                    continue;
                }
                $endpointId = $candidate;
                if (($endpoint['type'] ?? '') === 'read_write') {
                    break;
                }
            }
        }
        if ($endpointId === '') {
            throw new RuntimeException('Postgres size could not be changed.');
        }

        $response = $this->http()->patch('/projects/'.$projectId.'/endpoints/'.$endpointId, [
            'endpoint' => [
                'autoscaling_limit_min_cu' => $minCu,
                'autoscaling_limit_max_cu' => $maxCu,
                'suspend_timeout_seconds' => $suspendSeconds,
            ],
        ]);
        if (! $response->successful()) {
            throw new RuntimeException($this->failure($response->json(), 'Postgres size could not be changed.'));
        }
    }

    /**
     * Daily compute seconds and storage byte-hours per project. An empty list
     * when the account has no organization yet.
     *
     * @param  list<string>  $projectIds
     * @return array<string, array{compute_unit_seconds: int, storage_byte_hours: int, history_byte_hours: int, snapshot_byte_hours: int, transfer_bytes: int}>
     */
    public function consumption(CarbonInterface $date, array $projectIds): array
    {
        $projectIds = array_values(array_filter($projectIds, fn (string $id): bool => $id !== ''));
        if ($projectIds === []) {
            return [];
        }
        $organization = $this->organizationId($projectIds[0]);
        $from = $date->copy()->utc()->startOfDay();
        $response = $this->http()->get('/consumption_history/v2/projects', [
            'from' => $from->format('Y-m-d\TH:i:s\Z'),
            'to' => $from->copy()->addDay()->format('Y-m-d\TH:i:s\Z'),
            'granularity' => 'daily',
            'org_id' => $organization,
            'project_ids' => implode(',', $projectIds),
            'metrics' => 'compute_unit_seconds,root_branch_bytes_month,child_branch_bytes_month,instant_restore_bytes_month,snapshot_storage_bytes_month,public_network_transfer_bytes',
        ]);
        if (! $response->successful()) {
            throw new RuntimeException($this->failure($response->json(), 'Postgres usage could not be read.'));
        }

        $usage = [];
        foreach ((array) $response->json('projects') as $project) {
            if (! is_array($project)) {
                continue;
            }
            $id = (string) ($project['project_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $row = [
                'compute_unit_seconds' => 0,
                'storage_byte_hours' => 0,
                'history_byte_hours' => 0,
                'snapshot_byte_hours' => 0,
                'transfer_bytes' => 0,
            ];
            foreach ((array) ($project['periods'] ?? []) as $period) {
                foreach ((array) (is_array($period) ? ($period['consumption'] ?? []) : []) as $window) {
                    if (! is_array($window) || ! str_starts_with((string) ($window['timeframe_start'] ?? ''), $from->toDateString())) {
                        continue;
                    }
                    foreach ((array) ($window['metrics'] ?? []) as $metric) {
                        if (! is_array($metric)) {
                            continue;
                        }
                        $value = (int) ($metric['value'] ?? 0);
                        $row = match ((string) ($metric['metric_name'] ?? '')) {
                            'compute_unit_seconds' => array_merge($row, ['compute_unit_seconds' => $row['compute_unit_seconds'] + $value]),
                            'root_branch_bytes_month', 'child_branch_bytes_month' => array_merge($row, ['storage_byte_hours' => $row['storage_byte_hours'] + $value]),
                            'instant_restore_bytes_month' => array_merge($row, ['history_byte_hours' => $row['history_byte_hours'] + $value]),
                            'snapshot_storage_bytes_month' => array_merge($row, ['snapshot_byte_hours' => $row['snapshot_byte_hours'] + $value]),
                            'public_network_transfer_bytes' => array_merge($row, ['transfer_bytes' => $row['transfer_bytes'] + $value]),
                            default => $row,
                        };
                    }
                }
            }
            $usage[$id] = $row;
        }

        return $usage;
    }

    public function delete(string $projectId): void
    {
        if ($projectId === '') {
            return;
        }
        $response = $this->http()->delete('/projects/'.$projectId);
        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException($this->failure($response->json(), 'Postgres could not be removed.'));
        }
    }

    private function organizationId(string $projectId): string
    {
        if ($this->organization !== '') {
            return $this->organization;
        }
        $response = $this->http()->get('/projects/'.$projectId);
        $org = (string) data_get($response->json(), 'project.org_id', '');
        if ($org === '') {
            throw new RuntimeException('Postgres usage could not be read.');
        }

        return $org;
    }

    private function resolveRegion(?string $region): string
    {
        if (is_string($region) && isset(self::REGIONS[$region])) {
            return $region;
        }
        if (isset(self::REGIONS[$this->region])) {
            return $this->region;
        }

        return $this->region !== '' ? $this->region : 'aws-us-east-1';
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl('https://console.neon.tech/api/v2')
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    private function failure(mixed $json, string $fallback): string
    {
        $message = is_array($json) ? (string) ($json['message'] ?? '') : '';

        return $message !== '' ? $message : $fallback;
    }
}
