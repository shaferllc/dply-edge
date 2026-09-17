<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * SSRF guard for control-plane HTTP fetches of operator-supplied URLs.
 *
 * Resolves the host, rejects private / loopback / link-local / metadata
 * targets, and pins the request to a public address with redirects off so
 * a later DNS change or 30x cannot bounce onto RFC1918.
 */
final readonly class PublicOutboundUrl
{
    /**
     * @param  list<string>  $resolvedIps
     */
    private function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $pinIp,
        public array $resolvedIps,
    ) {}

    public static function parse(string $url): self
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            throw new UnsafeOutboundUrlException('URL is empty.');
        }

        $parts = parse_url($trimmed);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeOutboundUrlException('URL is invalid.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeOutboundUrlException('URL scheme is not allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeOutboundUrlException('URL credentials are not allowed.');
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === '' || self::isBlockedHostname($host)) {
            throw new UnsafeOutboundUrlException('Host is not allowed.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        // parse_url() already fails on ports above 65535, so only the low end
        // can reach here; port 0 is still rejected.
        if ($port < 1) {
            throw new UnsafeOutboundUrlException('Port is not allowed.');
        }

        $ips = self::resolveIps($host);
        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                throw new UnsafeOutboundUrlException('Host resolves to a private address.');
            }
        }

        return new self($trimmed, $host, $port, $ips[0], $ips);
    }

    /**
     * @return array<string, mixed>
     */
    public function httpClientOptions(): array
    {
        $resolve = $this->host.':'.$this->port.':'.$this->pinIp;

        return [
            'allow_redirects' => false,
            'curl' => [
                CURLOPT_RESOLVE => [$resolve],
            ],
        ];
    }

    public static function isBlockedIp(string $ip): bool
    {
        $ip = self::normalizeIp($ip);
        if ($ip === null) {
            return true;
        }

        if (str_starts_with(strtolower($ip), '::ffff:')) {
            return self::isBlockedIp(substr($ip, 7));
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        // CGNAT / shared-address space — used by some cloud metadata paths
        // and not covered by PHP's reserved-range flag.
        return self::ipv4InCidr($ip, '100.64.0.0/10');
    }

    /**
     * @return list<string>
     */
    private static function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        try {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
        } catch (\Throwable) {
            $records = false;
        }

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $fallback = gethostbynamel($host);
            $ips = is_array($fallback) ? $fallback : [];
        }

        $ips = array_values(array_unique($ips));
        if ($ips === []) {
            throw new UnsafeOutboundUrlException('Host could not be resolved.');
        }

        return $ips;
    }

    /** Public so save-time validation can reject these without paying for DNS. */
    public static function isBlockedHostname(string $host): bool
    {
        $blocked = [
            'localhost',
            'localhost.localdomain',
            'metadata',
            'metadata.internal',
            'metadata.google.internal',
            'kubernetes.default',
            'kubernetes.default.svc',
            'kubernetes.default.svc.cluster.local',
        ];

        if (in_array($host, $blocked, true)) {
            return true;
        }

        foreach (['.localhost', '.local', '.internal', '.intranet', '.corp'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeIp(string $ip): ?string
    {
        $ip = trim($ip, '[]');
        $packed = inet_pton($ip);

        return $packed === false ? null : (inet_ntop($packed) ?: null);
    }

    private static function ipv4InCidr(string $ip, string $cidr): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $mask = -1 << (32 - (int) $bits);

        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }
}
