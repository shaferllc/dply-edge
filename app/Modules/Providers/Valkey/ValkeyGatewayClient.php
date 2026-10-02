<?php

declare(strict_types=1);

namespace App\Modules\Providers\Valkey;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Control API of dply's own Valkey (packages/valkey-gateway, T-021).
 */
final class ValkeyGatewayClient
{
    public function __construct(
        private readonly string $apiUrl,
        private readonly string $token,
    ) {}

    public static function configured(?string $region = null): bool
    {
        return ValkeyRegions::configured($region);
    }

    /** The gateway of a region (ValkeyRegions); null or unknown means the default region. */
    public static function fromConfig(?string $region = null): self
    {
        if (! self::configured($region)) {
            throw new RuntimeException('dply Valkey is not configured for region '.ValkeyRegions::get($region)['key'].'. Set DPLY_VALKEY_API_URL and DPLY_VALKEY_TOKEN (or DPLY_VALKEY_REGIONS).');
        }
        $settings = ValkeyRegions::get($region);

        return new self($settings['api_url'], $settings['token']);
    }

    /**
     * Create or change a tenant. A new size or password restarts it on the next connection.
     *
     * @return array<string, mixed>
     */
    public function put(string $id, string $password, int $memoryMb, int $sleepAfter, bool $persistent, string $engine = 'valkey', int $diskGb = 0, ?int $backupDays = null): array
    {
        $body = [
            'password' => $password,
            'memory_mb' => $memoryMb,
            'sleep_after' => $sleepAfter,
            'persistent' => $persistent,
        ];
        if ($engine !== 'valkey') {
            // Databases: a volume of disk_gb that can grow but not shrink.
            $body['engine'] = $engine;
            $body['disk_gb'] = $diskGb;
            // Backup retention, the plan's (ruling r-78fm1ejqqy4c17en). Left out,
            // the gateway keeps what it has.
            if ($backupDays !== null) {
                $body['backup_days'] = $backupDays;
            }
        }

        return $this->http()->put('/tenants/'.$id, $body)->throw()->json() ?? [];
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        return $this->http()->get('/tenants/'.$id)->throw()->json() ?? [];
    }

    /**
     * Total awake seconds per tenant since it was created. Only goes up.
     *
     * @return array<string, int>
     */
    public function usage(): array
    {
        return $this->usageTotals()['awake_seconds'];
    }

    /**
     * Running totals per tenant: seconds awake, and commands served over the
     * REST API (valkey-gateway rest.go; absent from older gateways).
     *
     * @return array{awake_seconds: array<string, int>, rest_commands: array<string, int>}
     */
    public function usageTotals(): array
    {
        $body = $this->http()->get('/usage')->throw();
        $read = static fn (mixed $v): array => is_array($v) ? array_map('intval', $v) : [];

        return ['awake_seconds' => $read($body->json('awake_seconds')), 'rest_commands' => $read($body->json('rest_commands'))];
    }

    /**
     * Point-in-time restore of a database from its wal-g backups. Empty
     * $targetTime restores to the latest point. Can take minutes.
     *
     * @return array<string, mixed>
     */
    public function restore(string $id, string $targetTime = ''): array
    {
        return $this->http()->timeout(1200)->post('/tenants/'.$id.'/restore', ['target_time' => $targetTime])->throw()->json() ?? [];
    }

    /**
     * The database's last backup result from its agent: last_ok_at,
     * last_error, last_error_at (RFC3339), any of which may be missing.
     *
     * @return array<string, string>
     */
    public function backupStatus(string $id): array
    {
        $status = $this->http()->timeout(5)->get('/tenants/'.$id.'/backup')->throw()->json();

        return is_array($status) ? array_map('strval', $status) : [];
    }

    /**
     * Live stats from the database's agent (MongoDB: the app has no driver).
     * Wakes the database; a first wake on a node can take a while.
     *
     * @return array<string, mixed>
     */
    /**
     * A Valkey store's slowest recent commands (command and key only). Never
     * wakes an asleep store.
     *
     * @return array{awake: bool, entries: list<array{at: int, micros: int, command: string, key: string}>}
     */
    public function slowlog(string $id): array
    {
        $body = $this->http()->timeout(15)->get('/tenants/'.$id.'/slowlog')->throw()->json();

        return ['awake' => (bool) ($body['awake'] ?? false), 'entries' => array_values(array_filter((array) ($body['entries'] ?? []), 'is_array'))];
    }

    public function databaseStats(string $id): array
    {
        return $this->http()->timeout(25)->get('/tenants/'.$id.'/stats')->throw()->json() ?? [];
    }

    /**
     * A database's Insights (packages/valkey-gateway/dbagent/insights.go):
     * stats, top queries, running queries, index and vacuum health,
     * extensions. $cached never wakes it: a sleeping database answers with
     * the snapshot taken before it last stopped (awake: false).
     *
     * @return array<string, mixed>
     */
    public function insights(string $id, bool $cached = false): array
    {
        return $this->http()->timeout($cached ? 10 : 45)->get('/tenants/'.$id.'/insights', $cached ? ['cached' => 1] : [])->throw()->json() ?? [];
    }

    /**
     * Run a database action: queries-reset, cancel {pid}, extension {name},
     * readonly {password}, query {sql | collection, filter}, export, import
     * {key}. Wakes the database. The agent's message comes back as the error.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function action(string $id, string $name, array $body = []): array
    {
        $long = in_array($name, ['export', 'import'], true);
        $response = $this->http()->timeout($long ? 3600 : 60)->asJson()->post('/tenants/'.$id.'/action/'.$name, (object) $body);
        if ($response->failed()) {
            throw new RuntimeException(trim($response->body()) ?: 'The database did not answer ('.$response->status().').');
        }

        return $response->json() ?? [];
    }

    /**
     * This database's exports, newest last, each with an hour-long download link.
     *
     * @return list<array{file: string, key: string, bytes: int, at: string, url: string}>
     */
    public function databaseExports(string $id): array
    {
        return array_values((array) ($this->http()->timeout(10)->get('/tenants/'.$id.'/exports')->throw()->json('exports') ?? []));
    }

    /**
     * A signed PUT for tenants/{id}/imports/{file}, valid an hour.
     *
     * @return array{key: string, url: string}
     */
    public function databaseUploadLink(string $id, string $file): array
    {
        return $this->http()->timeout(10)->post('/tenants/'.$id.'/upload-link?file='.rawurlencode($file))->throw()->json();
    }

    public function sleep(string $id): void
    {
        $this->http()->post('/tenants/'.$id.'/sleep')->throw();
    }

    public function delete(string $id): void
    {
        $response = $this->http()->delete('/tenants/'.$id);
        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->apiUrl)->withToken($this->token)->acceptJson()->timeout(30);
    }
}
