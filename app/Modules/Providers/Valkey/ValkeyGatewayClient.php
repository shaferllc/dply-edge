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
    public function put(string $id, string $password, int $memoryMb, int $sleepAfter, bool $persistent, string $engine = 'valkey', int $diskGb = 0): array
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
        $totals = $this->http()->get('/usage')->throw()->json('awake_seconds');

        return is_array($totals) ? array_map('intval', $totals) : [];
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
