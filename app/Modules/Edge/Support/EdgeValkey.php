<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\Site;
use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Sizes not sold yet: they need the 64 GB node pool (m-8vcpu-64gb,
     * $336/mo per node), which is not created until a customer needs it.
     * Offering them without the pool leaves the pod unschedulable.
     */
    public const NOT_OFFERED = ['pro_25g', 'pro_50g'];

    /** @return array<string, array{label: string, memory_mb: int, sleeps: bool, per_second: float, cap_cents: int}> */
    public static function offered(): array
    {
        return array_diff_key(self::CLASSES, array_flip(self::NOT_OFFERED));
    }

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
        $class = isset(self::offered()[$class]) ? $class : self::DEFAULT_CLASS;
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

    /**
     * Dollars for display from (fractional) cents: four decimals under $1 so
     * minutes of use read $0.0017 rather than rounding to $0.01 or $0.00.
     */
    public static function money(float $cents): string
    {
        return number_format($cents / 100, $cents > 0 && $cents < 100 ? 4 : 2);
    }

    /** host:port an app connects to (TLS). */
    public static function address(string $target): string
    {
        return self::tenantId($target).'.'.config('edge.valkey.domain', 'cache.dply.local').':'.(int) config('edge.valkey.port', 6380);
    }

    /**
     * Connect the way an app does (TLS, user "default") and time a few
     * commands. A sleeping database wakes on connect, so that time is part of
     * "connect". Plain stream socket + RESP so no Redis extension is needed.
     *
     * @return array{ok: bool, error: ?string, steps: list<array{step: string, ms: float, result: string}>, ping_median_ms: ?float, ping_max_ms: ?float}
     */
    public static function probe(string $target, string $password): array
    {
        [$host, $port] = explode(':', self::address($target));
        $steps = [];
        $time = static function (string $step, callable $run) use (&$steps): string {
            $start = hrtime(true);
            try {
                $result = $run();
            } catch (\Throwable $e) {
                $steps[] = ['step' => $step, 'ms' => round((hrtime(true) - $start) / 1e6, 1), 'result' => 'failed'];

                throw $e;
            }
            $steps[] = ['step' => $step, 'ms' => round((hrtime(true) - $start) / 1e6, 1), 'result' => $result];

            return $result;
        };
        $out = static function (?string $error, array $pings = []) use (&$steps): array {
            return [
                'ok' => $error === null,
                'error' => $error,
                'steps' => $steps,
                'ping_median_ms' => $pings === [] ? null : $pings[intdiv(count($pings), 2)],
                'ping_max_ms' => $pings === [] ? null : max($pings),
            ];
        };

        try {
            $socket = null;
            $time('Connect (TLS, wakes it if asleep)', static function () use (&$socket, $host, $port): string {
                $socket = self::open($host, (int) $port);

                return 'connected';
            });
            $command = static fn (string ...$args): string => self::send($socket, ...$args);

            $key = 'dply:test:'.bin2hex(random_bytes(4));
            $value = bin2hex(random_bytes(8));
            $time('AUTH', fn () => $command('AUTH', 'default', $password));
            $time('SET (expires in 60s)', fn () => $command('SET', $key, $value, 'EX', '60'));
            $read = $time('GET', fn () => $command('GET', $key));
            if ($read !== $value) {
                throw new \RuntimeException('GET returned a different value.');
            }
            $time('DEL', fn () => $command('DEL', $key));
            $pings = [];
            for ($i = 0; $i < 10; $i++) {
                $start = hrtime(true);
                $command('PING');
                $pings[] = round((hrtime(true) - $start) / 1e6, 1);
            }
            sort($pings);
            fclose($socket);

            return $out(null, $pings);
        } catch (\Throwable $e) {
            return $out($e->getMessage());
        }
    }

    /**
     * Live numbers from INFO and DBSIZE. Connecting wakes a sleeping database.
     *
     * @return array<string, int|float|string|null>
     */
    public static function stats(string $target, string $password): array
    {
        [$host, $port] = explode(':', self::address($target));
        $socket = self::open($host, (int) $port);
        try {
            self::send($socket, 'AUTH', 'default', $password);
            $info = [];
            foreach (explode("\n", self::send($socket, 'INFO')) as $line) {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', trim($line), 2);
                    $info[$key] = $value;
                }
            }
            $keys = (int) self::send($socket, 'DBSIZE');
        } finally {
            fclose($socket);
        }
        $hits = (int) ($info['keyspace_hits'] ?? 0);
        $misses = (int) ($info['keyspace_misses'] ?? 0);

        return [
            'keys' => $keys,
            'used_memory' => (int) ($info['used_memory'] ?? 0),
            'max_memory' => (int) ($info['maxmemory'] ?? 0),
            'hit_rate' => $hits + $misses > 0 ? round($hits / ($hits + $misses) * 100, 1) : null,
            'hits' => $hits,
            'misses' => $misses,
            'commands' => (int) ($info['total_commands_processed'] ?? 0),
            'ops_per_sec' => (int) ($info['instantaneous_ops_per_sec'] ?? 0),
            'clients' => (int) ($info['connected_clients'] ?? 0),
            'expired_keys' => (int) ($info['expired_keys'] ?? 0),
            'evicted_keys' => (int) ($info['evicted_keys'] ?? 0),
            'uptime_seconds' => (int) ($info['uptime_in_seconds'] ?? 0),
            'version' => (string) ($info['valkey_version'] ?? $info['redis_version'] ?? ''),
        ];
    }

    /**
     * Jobs waiting per Laravel queue. Laravel prefixes its keys with the app
     * name (REDIS_PREFIX), so each queue's list is found by pattern and summed
     * server-side; the reply is one integer per queue.
     *
     * @param  list<string>  $queues
     * @return array<string, int>
     */
    public static function queueLengths(string $target, string $password, array $queues): array
    {
        [$host, $port] = explode(':', self::address($target));
        $socket = self::open($host, (int) $port);
        $sum = "local n = 0 for _, k in ipairs(redis.call('KEYS', ARGV[1])) do if redis.call('TYPE', k).ok == 'list' then n = n + redis.call('LLEN', k) end end return n";
        try {
            self::send($socket, 'AUTH', 'default', $password);
            $out = [];
            foreach ($queues as $queue) {
                $out[$queue] = (int) self::send($socket, 'EVAL', $sum, '0', '*queues:'.$queue);
            }

            return $out;
        } finally {
            fclose($socket);
        }
    }

    /** @return resource TLS socket to a tenant, as an app connects. */
    /**
     * Jobs waiting on these queues, cheap enough to ask every few seconds.
     * Laravel's list is `{prefix}queues:{name}` and only the app knows its
     * prefix, so the key is found once (SCAN, server side) and remembered;
     * after that each check is one LLEN.
     *
     * @param  list<string>  $queues
     */
    public static function queueBacklog(string $target, string $password, array $queues): int
    {
        [$host, $port] = explode(':', self::address($target));
        $socket = self::open($host, (int) $port);
        $find = "local c = '0' repeat local r = redis.call('SCAN', c, 'MATCH', ARGV[1], 'COUNT', 1000) c = r[1] "
            ."for _, k in ipairs(r[2]) do if redis.call('TYPE', k).ok == 'list' then return k end end until c == '0' return false";
        try {
            self::send($socket, 'AUTH', 'default', $password);
            $total = 0;
            foreach ($queues as $queue) {
                $remember = 'edge:valkey:'.$target.':queue-key:'.$queue;
                $key = Cache::get($remember);
                if (! is_string($key) || $key === '') {
                    $key = self::send($socket, 'EVAL', $find, '0', '*queues:'.$queue);
                    if ($key === '(nil)' || $key === '') {
                        continue; // nothing queued yet: Laravel creates the list on the first push
                    }
                    Cache::put($remember, $key, now()->addHour());
                }
                $total += (int) self::send($socket, 'LLEN', $key);
            }

            return $total;
        } finally {
            fclose($socket);
        }
    }

    private static function open(string $host, int $port)
    {
        $context = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true]]);
        $socket = @stream_socket_client("tls://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException("Could not connect: {$errstr}");
        }
        stream_set_timeout($socket, 15);

        return $socket;
    }

    /**
     * One RESP command. Simple, integer and bulk replies are enough for
     * AUTH/SET/GET/DEL/PING/INFO/DBSIZE.
     *
     * @param  resource  $socket
     */
    private static function send($socket, string ...$args): string
    {
        $payload = '*'.count($args)."\r\n";
        foreach ($args as $arg) {
            $payload .= '$'.strlen($arg)."\r\n".$arg."\r\n";
        }
        fwrite($socket, $payload);
        $line = fgets($socket);
        if ($line === false) {
            throw new \RuntimeException('The connection closed.');
        }
        $line = rtrim($line, "\r\n");
        if ($line[0] === '-') {
            throw new \RuntimeException(substr($line, 1));
        }
        if ($line[0] === '$') {
            $length = (int) substr($line, 1);
            if ($length < 0) {
                return '(nil)';
            }
            $body = '';
            while (strlen($body) < $length + 2 && ! feof($socket)) {
                $body .= (string) fread($socket, $length + 2 - strlen($body));
            }

            return substr($body, 0, $length);
        }

        return substr($line, 1);
    }

    public static function url(string $id, string $password): string
    {
        $domain = (string) config('edge.valkey.domain', 'cache.dply.local');
        $port = (int) config('edge.valkey.port', 6380);

        return 'rediss://default:'.rawurlencode($password).'@'.$id.'.'.$domain.':'.$port;
    }
}
