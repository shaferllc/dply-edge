<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\Site;
use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use Illuminate\Support\Str;

/**
 * dply's own Valkey on the Resources page (ruling r-72p0gkdn9dqwxqha, T-021).
 * A Redis connection whose target starts with "valkey:" is one of ours; the
 * rest of the target is the gateway tenant id. The address reaches the app as
 * REDIS_URL, the same as any Redis.
 */
final class EdgeValkey
{
    public const PREFIX = 'valkey:';

    /**
     * Owner's price table (2026-09-24). Flex sleeps when idle; pro stays on
     * and keeps an append-only file. Billed per second awake, up to the cap.
     *
     * @var array<string, array{label: string, memory_mb: int, sleeps: bool, per_second: float, cap_cents: int}>
     */
    public const CLASSES = [
        'flex_250m' => ['label' => 'Flex 250 MB', 'memory_mb' => 250, 'sleeps' => true, 'per_second' => 0.00000248, 'cap_cents' => 600],
        'flex_1g' => ['label' => 'Flex 1 GB', 'memory_mb' => 1024, 'sleeps' => true, 'per_second' => 0.00000992, 'cap_cents' => 2400],
        'flex_2_5g' => ['label' => 'Flex 2.5 GB', 'memory_mb' => 2560, 'sleeps' => true, 'per_second' => 0.0000198, 'cap_cents' => 4800],
        'pro_5g' => ['label' => 'Pro 5 GB', 'memory_mb' => 5120, 'sleeps' => false, 'per_second' => 0.0000318, 'cap_cents' => 7700],
        'pro_12g' => ['label' => 'Pro 12 GB', 'memory_mb' => 12288, 'sleeps' => false, 'per_second' => 0.0000744, 'cap_cents' => 18000],
        'pro_25g' => ['label' => 'Pro 25 GB', 'memory_mb' => 25600, 'sleeps' => false, 'per_second' => 0.000103, 'cap_cents' => 25000],
        'pro_50g' => ['label' => 'Pro 50 GB', 'memory_mb' => 51200, 'sleeps' => false, 'per_second' => 0.000207, 'cap_cents' => 50000],
    ];

    public const DEFAULT_CLASS = 'flex_250m';

    /** Idle time before a flex database sleeps, in seconds. 0 stays on. */
    public const SLEEPS = [
        300 => '5 minutes',
        900 => '15 minutes',
        3600 => '1 hour',
        0 => 'Stays on',
    ];

    public const DEFAULT_SLEEP = 300;

    public static function isTarget(string $target): bool
    {
        return str_starts_with($target, self::PREFIX);
    }

    public static function tenantId(string $target): string
    {
        return substr($target, strlen(self::PREFIX));
    }

    public static function sleepAfter(string $class, int $sleep): int
    {
        if (! (self::CLASSES[$class]['sleeps'] ?? false)) {
            return 0;
        }

        return array_key_exists($sleep, self::SLEEPS) ? $sleep : self::DEFAULT_SLEEP;
    }

    /**
     * Start a Valkey for this app. Returns the connection target and the address.
     *
     * @return array{target: string, url: string}
     */
    public static function provision(Site $site, string $resource, string $class, int $sleep): array
    {
        $class = isset(self::CLASSES[$class]) ? $class : self::DEFAULT_CLASS;
        $spec = self::CLASSES[$class];
        $label = substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($resource)), '-'), 0, 12);
        $id = trim(strtolower((string) $site->id).'-'.$label, '-');
        $password = Str::random(40);

        ValkeyGatewayClient::fromConfig()->put($id, $password, $spec['memory_mb'], self::sleepAfter($class, $sleep), ! $spec['sleeps']);

        return ['target' => self::PREFIX.$id, 'url' => self::url($id, $password)];
    }

    /** New size or sleep time. The password stays; it is read back from REDIS_URL. */
    public static function update(string $target, string $url, string $class, int $sleep): void
    {
        $spec = self::CLASSES[$class] ?? self::CLASSES[self::DEFAULT_CLASS];
        $password = rawurldecode((string) (parse_url($url, PHP_URL_PASS) ?? ''));
        ValkeyGatewayClient::fromConfig()->put(self::tenantId($target), $password, $spec['memory_mb'], self::sleepAfter($class, $sleep), ! $spec['sleeps']);
    }

    public static function destroy(string $target): void
    {
        ValkeyGatewayClient::fromConfig()->delete(self::tenantId($target));
    }

    public static function url(string $id, string $password): string
    {
        $domain = (string) config('edge.valkey.domain', 'cache.dply.local');
        $port = (int) config('edge.valkey.port', 6380);

        return 'rediss://default:'.rawurlencode($password).'@'.$id.'.'.$domain.':'.$port;
    }
}
