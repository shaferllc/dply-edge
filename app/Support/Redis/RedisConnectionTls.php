<?php

declare(strict_types=1);

namespace App\Support\Redis;

/**
 * DigitalOcean managed Redis/Valkey (and Upstash) are TLS-only. A plaintext
 * PhpRedis dial surfaces as "read error on connection" at Redis->auth() and
 * 500s every request that touches cache (ThrottleRequests, Livewire).
 *
 * Laravel's default REDIS_SCHEME is `tcp`. Infer `tls` / `rediss` from the
 * host and port so a control-plane deploy that restores a stale `.env`
 * without REDIS_SCHEME still handshakes. Local 127.0.0.1 / ::1 stays tcp.
 */
final class RedisConnectionTls
{
    public const DO_MANAGED_PORT = 25061;

    /**
     * @param  array<string, mixed>  $vars
     * @return array<string, mixed>
     */
    public static function ensureEnv(array $vars): array
    {
        $host = self::stringOrNull($vars['REDIS_HOST'] ?? null);
        $port = $vars['REDIS_PORT'] ?? null;
        $url = self::stringOrNull($vars['REDIS_URL'] ?? null);

        if (! self::requiresTls($host, $port, $url)) {
            return $vars;
        }

        $vars['REDIS_SCHEME'] = 'tls';

        $rewritten = self::url($url, $host, $port);
        if ($rewritten !== null && $rewritten !== '') {
            $vars['REDIS_URL'] = $rewritten;
        }

        return $vars;
    }

    public static function scheme(?string $scheme, ?string $host, int|string|null $port, ?string $url = null): string
    {
        $explicit = strtolower(trim((string) $scheme));
        if (in_array($explicit, ['tls', 'rediss', 'ssl'], true)) {
            return 'tls';
        }

        if (self::requiresTls($host, $port, $url)) {
            return 'tls';
        }

        return $explicit !== '' ? $explicit : 'tcp';
    }

    public static function url(?string $url, ?string $host, int|string|null $port): ?string
    {
        $url = self::stringOrNull($url);
        if ($url === null) {
            return null;
        }

        if (! self::requiresTls($host, $port, $url)) {
            return $url;
        }

        if (str_starts_with(strtolower($url), 'redis://')) {
            return 'rediss://'.substr($url, strlen('redis://'));
        }

        return $url;
    }

    public static function requiresTls(?string $host, int|string|null $port, ?string $url = null): bool
    {
        $fromUrl = self::parseUrl($url);
        $host = self::stringOrNull($host) ?? $fromUrl['host'];
        $port = $port !== null && trim((string) $port) !== '' ? $port : $fromUrl['port'];

        if (self::isLoopback($host)) {
            return false;
        }

        if ((int) $port === self::DO_MANAGED_PORT) {
            return true;
        }

        $host = strtolower((string) $host);

        return $host !== '' && (
            str_ends_with($host, '.db.ondigitalocean.com')
            || str_ends_with($host, '.ondigitalocean.com')
            || str_contains($host, '.upstash.io')
            || str_ends_with($host, '.upstash.io')
        );
    }

    /**
     * @return array{host: ?string, port: ?int}
     */
    private static function parseUrl(?string $url): array
    {
        $url = self::stringOrNull($url);
        if ($url === null) {
            return ['host' => null, 'port' => null];
        }

        $parts = parse_url($url);

        return [
            'host' => isset($parts['host']) ? self::stringOrNull($parts['host']) : null,
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
        ];
    }

    private static function isLoopback(?string $host): bool
    {
        $host = strtolower(trim((string) $host));

        return in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
