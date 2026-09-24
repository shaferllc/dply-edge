<?php

declare(strict_types=1);

namespace App\Modules\Providers\Upstash;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Platform Upstash account. Creates a pay-as-you-go Redis the container
 * dials, and reads the stats we bill. The password never leaves this class
 * except inside the returned connection URL.
 *
 * Called from EdgeContainerConnections and EdgeRedisUsageCollector.
 * API: POST /redis/database, DELETE /redis/database/{id}, GET /redis/stats/{id}.
 * User request: "ok so how can we implement upstash and bill for it".
 */
final class UpstashRedisClient
{
    /** @var array<string, string> */
    public const REGIONS = [
        'us-east-1' => 'US East (N. Virginia)',
        'us-east-2' => 'US East (Ohio)',
        'us-west-1' => 'US West (N. California)',
        'us-west-2' => 'US West (Oregon)',
        'ca-central-1' => 'Canada (Central)',
        'eu-central-1' => 'Europe (Frankfurt)',
        'eu-west-1' => 'Europe (Ireland)',
        'eu-west-2' => 'Europe (London)',
        'sa-east-1' => 'South America (São Paulo)',
        'ap-south-1' => 'Asia Pacific (Mumbai)',
        'ap-northeast-1' => 'Asia Pacific (Tokyo)',
        'ap-southeast-1' => 'Asia Pacific (Singapore)',
        'ap-southeast-2' => 'Asia Pacific (Sydney)',
        'af-south-1' => 'Africa (Cape Town)',
    ];

    public function __construct(
        private readonly string $email,
        private readonly string $apiKey,
        private readonly string $region,
    ) {}

    public static function configured(): bool
    {
        return config('edge.upstash.email') !== null
            && config('edge.upstash.email') !== ''
            && config('edge.upstash.api_key') !== null
            && config('edge.upstash.api_key') !== '';
    }

    public static function fromConfig(): self
    {
        $email = (string) config('edge.upstash.email');
        $apiKey = (string) config('edge.upstash.api_key');
        if ($email === '' || $apiKey === '') {
            throw new \RuntimeException('Redis cannot be started from here yet.');
        }

        return new self($email, $apiKey, (string) config('edge.upstash.region', 'us-east-1'));
    }

    /**
     * @return array{id: string, url: string}
     */
    /** @var list<string> */
    public const PLANS = ['payg', 'fixed_250mb', 'fixed_1gb', 'fixed_5gb', 'fixed_10gb', 'fixed_50gb', 'fixed_100gb', 'fixed_500gb'];

    public function create(string $name, ?string $region = null, string $plan = 'payg'): array
    {
        $region = $region !== null && $region !== '' ? $region : $this->region;
        if (! isset(self::REGIONS[$region])) {
            throw new \InvalidArgumentException('Pick a region.');
        }
        if (! in_array($plan, self::PLANS, true)) {
            $plan = 'payg';
        }
        $created = $this->http()->post('/redis/database', [
            'database_name' => $name,
            'platform' => 'aws',
            'primary_region' => $region,
            'plan' => $plan,
            'tls' => true,
            'eviction' => true,
        ])->throw()->json();
        if (! is_array($created)) {
            throw new \RuntimeException('Redis was created but no address came back.');
        }

        $id = (string) ($created['database_id'] ?? '');
        $password = (string) ($created['password'] ?? '');
        $endpoint = (string) ($created['endpoint'] ?? '');
        if ($id === '' || $password === '' || $endpoint === '') {
            throw new \RuntimeException('Redis was created but no address came back.');
        }
        $host = str_contains($endpoint, '.') ? $endpoint : $endpoint.'.upstash.io';
        $port = (int) ($created['port'] ?? 6379);

        return [
            'id' => $id,
            'url' => sprintf('rediss://default:%s@%s:%d', rawurlencode($password), $host, $port > 0 ? $port : 6379),
        ];
    }

    public function delete(string $id): void
    {
        $this->http()->delete('/redis/database/'.$id)->throw();
    }

    /**
     * @return array<string, mixed>
     */
    public function database(string $id): array
    {
        $body = $this->http()->get('/redis/database/'.$id)->throw()->json();

        return is_array($body) ? $body : [];
    }

    public function rename(string $id, string $name): void
    {
        $this->http()->post('/redis/rename/'.$id, ['name' => $name])->throw();
    }

    /**
     * @return array<string, mixed>
     */
    public function resetPassword(string $id): array
    {
        $body = $this->http()->post('/redis/reset-password/'.$id)->throw()->json();

        return is_array($body) ? $body : [];
    }

    public function enableTls(string $id): void
    {
        $this->http()->post('/redis/enable-tls/'.$id)->throw();
    }

    public function setEviction(string $id, bool $enabled): void
    {
        $this->http()->post('/redis/'.($enabled ? 'enable-eviction' : 'disable-eviction').'/'.$id)->throw();
    }

    public function setDailyBackup(string $id, bool $enabled): void
    {
        $this->http()->patch('/redis/'.($enabled ? 'enable-dailybackup' : 'disable-dailybackup').'/'.$id)->throw();
    }

    public function updateBudget(string $id, int $budget): void
    {
        $this->http()->patch('/redis/update-budget/'.$id, ['budget' => $budget])->throw();
    }

    public function changePlan(string $id, string $plan): void
    {
        if (! in_array($plan, self::PLANS, true)) {
            throw new \InvalidArgumentException('Pick a plan.');
        }
        $this->http()->post('/redis/'.$id.'/change-plan', [
            'plan_name' => $plan,
            'auto_upgrade' => false,
        ])->throw();
    }

    /**
     * @param  list<string>  $regions
     */
    public function updateReadRegions(string $id, array $regions): void
    {
        $regions = array_values(array_filter($regions, static fn (string $region): bool => isset(self::REGIONS[$region])));
        $this->http()->post('/redis/update-regions/'.$id, ['read_regions' => $regions])->throw();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function backups(string $id): array
    {
        $body = $this->http()->get('/redis/list-backup/'.$id)->throw()->json();

        return is_array($body) ? array_values(array_filter($body, 'is_array')) : [];
    }

    public function createBackup(string $id, string $name): void
    {
        $this->http()->post('/redis/create-backup/'.$id, ['name' => $name])->throw();
    }

    public function deleteBackup(string $id, string $backupId): void
    {
        $this->http()->delete('/redis/delete-backup/'.$id.'/'.$backupId)->throw();
    }

    public function restoreBackup(string $id, string $backupId): void
    {
        $this->http()->post('/redis/restore-backup/'.$id, ['backup_id' => $backupId])->throw();
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(string $id): array
    {
        $stats = $this->http()->get('/redis/stats/'.$id)->throw()->json();

        return is_array($stats) ? $stats : [];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl('https://api.upstash.com/v2')
            ->withBasicAuth($this->email, $this->apiKey)
            ->acceptJson()
            ->asJson();
    }
}
