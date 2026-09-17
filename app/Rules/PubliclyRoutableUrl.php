<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Http\PublicOutboundUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Save-time guard for operator-supplied URLs that something on Cloudflare's
 * network will later fetch — an Edge hybrid origin, above all.
 *
 * Deliberately structural: it rejects literal private/loopback/link-local
 * addresses and internal-only hostnames, and does NOT resolve DNS. The
 * strict, DNS-pinned check already exists in PublicOutboundUrl::parse() and
 * runs where a request is actually made (the origin healthcheck and the
 * "Test origin" button). Resolving here would put a network call in the save
 * path and reject a host whose DNS has not propagated yet.
 *
 * The point of the rule is the message. A private origin used to save,
 * publish into the worker host map, and then silently never fire, because the
 * Worker cannot route to RFC1918 — so the failure surfaced as "my origin does
 * nothing" rather than as anything actionable.
 */
final class PubliclyRoutableUrl implements ValidationRule
{
    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return; // `required` / `string` own this case.
        }

        $parts = parse_url(trim($value));
        if (! is_array($parts) || ! isset($parts['host'])) {
            return; // `url:http,https` owns this case.
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (PublicOutboundUrl::isBlockedIp($host)) {
                $fail(__(':host is a private address — the origin must be reachable from the public internet. To expose a backend with no public IP, put it behind a Cloudflare Tunnel and use the tunnel hostname.', ['host' => $host]));
            }

            return;
        }

        if (PublicOutboundUrl::isBlockedHostname($host)) {
            $fail(__(':host is an internal-only hostname — the origin must be reachable from the public internet.', ['host' => $host]));
        }
    }
}
