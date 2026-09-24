<?php

declare(strict_types=1);

namespace Dply\Laravel;

use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Http;

/**
 * Cache store for an attached key-value resource. Registered by
 * DplyServiceProvider from DPLY_KV_HOST. No table.
 * User: "add support for key value store to dply/laravel ad dply/rails".
 */
final class DplyKvStore implements Store
{
    public function __construct(private readonly string $host) {}

    public function get($key): mixed
    {
        $response = Http::timeout(15)->get($this->url((string) $key));
        if (! $response->successful()) {
            return null;
        }
        $value = @unserialize($response->body());

        return $value === false && $response->body() !== 'b:0;' ? $response->body() : $value;
    }

    public function many(array $keys): array
    {
        $found = [];
        foreach ($keys as $key) {
            $found[$key] = $this->get($key);
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

    public function putMany(array $values, $seconds): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->put($key, $value, $seconds) && $ok;
        }

        return $ok;
    }

    public function increment($key, $value = 1): int|bool
    {
        $current = $this->get($key);
        $next = (is_int($current) || is_float($current) ? $current : 0) + $value;
        $this->forever($key, $next);

        return (int) $next;
    }

    public function decrement($key, $value = 1): int|bool
    {
        return $this->increment($key, $value * -1);
    }

    public function forever($key, $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function forget($key): bool
    {
        return Http::timeout(15)->delete($this->url((string) $key))->successful();
    }

    public function flush(): bool
    {
        $names = Http::timeout(15)->get($this->url(''))->json('keys');
        if (! is_array($names)) {
            return false;
        }
        foreach ($names as $name) {
            if (is_string($name)) {
                $this->forget($name);
            }
        }

        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }

    private function url(string $key): string
    {
        $key = ltrim($key, '/');

        return 'http://'.$this->host.($key === '' ? '/' : '/'.rawurlencode($key));
    }
}
