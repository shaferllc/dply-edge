<?php

declare(strict_types=1);

namespace Dply\Laravel;

use BadMethodCallException;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cache store for an attached key-value resource. Registered by
 * DplyServiceProvider from DPLY_KV_HOST. No table.
 * User: "add support for key value store to dply/laravel ad dply/rails".
 */
final class DplyKvStore implements Store
{
    public const NO_COUNTERS = "Key-value stores can't count atomically. Attach Valkey (Redis) or use State for counters, locks and rate limiting.";

    public function __construct(private readonly string $host) {}

    public function get($key): mixed
    {
        $response = Http::timeout(15)->get($this->url((string) $key));

        return $response->successful() ? $this->decode($response->body()) : null;
    }

    /** One POST per 100 keys: the proxy's bulk read. */
    public function many(array $keys): array
    {
        $found = [];
        foreach (array_chunk(array_map('strval', $keys), 100) as $chunk) {
            $values = Http::timeout(15)->post($this->url(''), ['keys' => $chunk])->json('values');
            foreach ($chunk as $key) {
                $raw = is_array($values) ? ($values[$key] ?? null) : null;
                $found[$key] = is_string($raw) ? $this->decode($raw) : null;
            }
        }

        return $found;
    }

    public function put($key, $value, $seconds): bool
    {
        $request = Http::timeout(15)->withBody(serialize($value), 'application/octet-stream');
        if ((int) $seconds >= 60) {
            $request = $request->withHeader('x-dply-ttl', (string) (int) $seconds);
        }

        return $request->put($this->url((string) $key))->successful();
    }

    /** The PUTs go out together; there is no bulk write. */
    public function putMany(array $values, $seconds): bool
    {
        $responses = Http::pool(function (Pool $pool) use ($values, $seconds): array {
            $requests = [];
            foreach ($values as $key => $value) {
                $request = $pool->timeout(15)->withBody(serialize($value), 'application/octet-stream');
                if ((int) $seconds >= 60) {
                    $request = $request->withHeader('x-dply-ttl', (string) (int) $seconds);
                }
                $requests[] = $request->put($this->url((string) $key));
            }

            return $requests;
        });

        foreach ($responses as $response) {
            if (! $response instanceof Response || ! $response->successful()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Key-value stores are eventually consistent: a read-then-write counter
     * loses updates under concurrency, so fail loudly instead.
     */
    public function increment($key, $value = 1): int|bool
    {
        throw new BadMethodCallException(self::NO_COUNTERS);
    }

    public function decrement($key, $value = 1): int|bool
    {
        throw new BadMethodCallException(self::NO_COUNTERS);
    }

    public function forever($key, $value): bool
    {
        return $this->put($key, $value, 0);
    }

    /** KV has no expire-only call: rewrite the stored bytes with the new TTL. */
    public function touch($key, $seconds): bool
    {
        $response = Http::timeout(15)->get($this->url((string) $key));
        if (! $response->successful()) {
            return false;
        }
        $request = Http::timeout(15)->withBody($response->body(), 'application/octet-stream');
        if ((int) $seconds >= 60) {
            $request = $request->withHeader('x-dply-ttl', (string) (int) $seconds);
        }

        return $request->put($this->url((string) $key))->successful();
    }

    public function forget($key): bool
    {
        return Http::timeout(15)->delete($this->url((string) $key))->successful();
    }

    /** Walks every page of keys; the proxy returns up to 1000 per page. */
    public function flush(): bool
    {
        $cursor = null;
        do {
            $page = Http::timeout(15)->get($this->url(''), array_filter(['cursor' => $cursor]))->json();
            if (! is_array($page) || ! is_array($page['keys'] ?? null)) {
                return false;
            }
            foreach ($page['keys'] as $name) {
                if (is_string($name)) {
                    $this->forget($name);
                }
            }
            $cursor = is_string($page['cursor'] ?? null) ? $page['cursor'] : null;
        } while ($cursor !== null);

        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }

    private function decode(string $body): mixed
    {
        $value = @unserialize($body);

        return $value === false && $body !== 'b:0;' ? $body : $value;
    }

    private function url(string $key): string
    {
        $key = ltrim($key, '/');

        return 'http://'.$this->host.($key === '' ? '/' : '/'.rawurlencode($key));
    }
}
