<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Normalize stored repository identifiers into something {@code git clone} /
 * {@code git ls-remote} can fetch — and refuse the ones that should never
 * reach the network.
 *
 * Serverless (and a few other surfaces) persist GitHub repos as bare
 * {@code owner/name} shorthand. That form is not a valid clone target — git
 * treats it as a local path — so callers that talk to a remote must expand it
 * first.
 */
final class GitCloneUrl
{
    /**
     * Schemes a clone may use. `file://` would read the control-plane's own
     * disk and `git://` is both unauthenticated and a convenient way to reach
     * an internal host, so neither is accepted.
     */
    private const ALLOWED_SCHEMES = ['http', 'https', 'ssh'];

    /**
     * Turn a bare {@code owner/name} repo shorthand into a clone-able GitHub
     * HTTPS URL. Anything already URL-shaped (https / git / ssh / scp) is
     * returned untouched.
     */
    public static function normalize(string $repositoryUrl): string
    {
        $repositoryUrl = trim($repositoryUrl);

        if ($repositoryUrl === '') {
            return '';
        }

        if (preg_match('#^(https?://|git://|ssh://|git@)#i', $repositoryUrl) === 1) {
            return $repositoryUrl;
        }

        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repositoryUrl) === 1) {
            return 'https://github.com/'.$repositoryUrl.'.git';
        }

        return $repositoryUrl;
    }

    /**
     * Refuse a clone target that points at this host, this network, or the
     * local filesystem.
     *
     * A clone is an outbound fetch performed *by the control plane* against a
     * URL the caller supplied, and clone stderr flows back to that caller
     * through the deploy log and the detection error path. Without this, the
     * repository field is a request-forgery primitive: `file:///srv/...`,
     * `git://10.0.0.5/x`, or a link-local metadata address all clone happily.
     *
     * Operators running an internal git host pass its hostname through
     * `$allowedHosts` (exact match, or a leading-dot suffix for a whole
     * domain), which skips the address checks for that host only.
     *
     * @param  list<string>  $allowedHosts
     *
     * @throws InvalidArgumentException
     */
    public static function assertClonable(
        string $repositoryUrl,
        array $allowedHosts = [],
        bool $allowLocalPaths = false,
    ): void {
        $url = self::normalize($repositoryUrl);

        if ($url === '') {
            throw new InvalidArgumentException('A repository URL is required.');
        }

        $host = self::hostOf($url);

        if ($host === null) {
            // A bare filesystem path is a valid clone target for git, and the
            // test suite uses one. It is refused by default because in
            // production the only thing that reaches it is a caller trying to
            // read the control plane's disk.
            if ($allowLocalPaths && self::looksLikeLocalPath($url)) {
                return;
            }

            throw new InvalidArgumentException(
                'That repository URL is not a supported clone target. Use an https:// or ssh:// URL, '
                .'a git@host:owner/name address, or GitHub owner/name shorthand.'
            );
        }

        if (self::hostIsAllowlisted($host, $allowedHosts)) {
            return;
        }

        if (self::hostIsLocal($host) || self::resolvesOnlyToLocal($host)) {
            throw new InvalidArgumentException(sprintf(
                'Refusing to clone from "%s" — that address is on the platform\'s own network. '
                .'Use a publicly reachable git host, or ask an operator to allowlist it.',
                $host,
            ));
        }
    }

    /**
     * Catch the name form of the same attack: a hostname whose every A record
     * points into private or link-local space.
     *
     * Deliberately fails *open*. A DNS hiccup must not turn a legitimate
     * deploy into a refusal, so an unresolvable name is left to git. This also
     * cannot stop DNS rebinding — the address is re-resolved when git
     * connects — which is why {@see hostIsLocal} still checks the literal
     * form. It raises the bar; it is not a sandbox.
     */
    private static function resolvesOnlyToLocal(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false; // Already handled by hostIsLocal().
        }

        $addresses = @gethostbynamel($host);
        if ($addresses === false || $addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (! self::hostIsLocal($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A plain path with no scheme — never a `file://` URL, which stays refused
     * even when local paths are permitted.
     */
    private static function looksLikeLocalPath(string $url): bool
    {
        return parse_url($url, PHP_URL_SCHEME) === null && ! str_contains($url, '://');
    }

    /**
     * Extract the host from an https/ssh/scp-style clone target, or null when
     * the target is not one of those (a bare path, `file://`, `git://`, …).
     */
    private static function hostOf(string $url): ?string
    {
        // scp-style: git@host:owner/name — no scheme, colon separates host.
        if (preg_match('#^[A-Za-z0-9_.-]+@([^:/\s]+):#', $url, $m) === 1) {
            return strtolower($m[1]);
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme === '' || ! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return strtolower(trim($host, '[]'));
    }

    /**
     * @param  list<string>  $allowedHosts
     */
    private static function hostIsAllowlisted(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            if ($allowed === '') {
                continue;
            }
            if ($host === $allowed) {
                return true;
            }
            // ".example.com" allows any subdomain of example.com.
            if (str_starts_with($allowed, '.') && str_ends_with($host, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Loopback, link-local, private, and carrier-NAT space — plus the names
     * that resolve there by convention. A hostname that merely *resolves* to a
     * private address is not caught here: that needs resolution at connect
     * time, which git owns. This blocks the literal forms, which is what a
     * hand-written probe uses.
     */
    private static function hostIsLocal(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // Anything not in the public unicast range: loopback, private, and
        // reserved. FILTER_FLAG_NO_RES_RANGE covers 0/8, 169.254/16, 127/8
        // and ::1; NO_PRIV_RANGE covers 10/8, 172.16/12, 192.168/16, fc00::/7.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        // 100.64.0.0/10 (carrier-grade NAT) is public-looking but routes
        // inside many hosting networks; PHP's filters do not cover it.
        if (preg_match('#^100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.#', $host) === 1) {
            return true;
        }

        return false;
    }
}
