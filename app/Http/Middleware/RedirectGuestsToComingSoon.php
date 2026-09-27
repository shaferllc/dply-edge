<?php

namespace App\Http\Middleware;

use App\Models\ComingSoonAllowedIp;
use App\Support\MachineCallbackPaths;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class RedirectGuestsToComingSoon
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // COMING_SOON=true forces the gate on (even locally, for preview).
        // Default is off — the public site is live.
        if (! filter_var(config('dply.coming_soon'), FILTER_VALIDATE_BOOLEAN) || auth()->check()) {
            return $next($request);
        }

        // Allow-listed IPs see the FULL site; everyone else only sees the
        // coming-soon page (plus the Livewire requests it needs to render and
        // run the waitlist signup form).
        if ($this->ipAllowed($request)) {
            return $next($request);
        }

        if ($request->routeIs('coming-soon') || $request->is('livewire/*')) {
            return $next($request);
        }

        // Machine-to-machine callbacks must never be redirected to the
        // coming-soon teaser. Provisioned servers POST task lifecycle
        // results to /webhook/task/* (update-output, mark-as-failed, …) and
        // deploy/git callbacks land on /hooks/*; the uptime probe hits /up.
        // These have their own auth (signed URLs / webhook secrets / throttle)
        // and carry no session, so a 302 here silently swallows the POST.
        if ($this->isMachineCallback($request)) {
            return $next($request);
        }

        // Public, unauthenticated surfaces that must stay reachable even while
        // the beta gate is up: social-login (OAuth) and passkey-login round
        // trips (the visitor has no session yet at the callback), the
        // `curl … | sh` CLI installer, public status pages, and one-time
        // credential-share links.
        if ($this->isPublicDuringComingSoon($request)) {
            return $next($request);
        }

        return redirect()->route('coming-soon');
    }

    /**
     * Unauthenticated machine callbacks that must bypass the coming-soon gate
     * (task lifecycle webhooks, deploy/git hooks, function URLs, health probe).
     * Each carries its own request authentication, so skipping the gate is safe.
     * Sourced from the shared canonical list so this can't drift from the other
     * guest gate (maintenance mode) or the CSRF except-list.
     */
    private function isMachineCallback(Request $request): bool
    {
        return MachineCallbackPaths::matches($request)
            || $request->routeIs('webhook.*');
    }

    /**
     * Public guest-facing routes that should remain reachable during the beta
     * coming-soon window (login round-trips, CLI install, status, share links).
     */
    private function isPublicDuringComingSoon(Request $request): bool
    {
        return $request->is(
            'auth/*/redirect',   // OAuth social-login start
            'auth/*/callback',   // OAuth social-login return (no session yet)
            'passkeys/*',        // passkey login options/verify (mgmt routes are auth-gated anyway)
            'cli/*',             // `curl https://dply.io/cli/install.sh | sh`
            'status/*',          // public status pages
            'share/*',           // one-time credential-share links
            'security.txt',      // RFC 9116 security contact
            '.well-known/security.txt',
        );
    }

    /**
     * Is the request from an allow-listed IP? Those clients (and authenticated
     * users) see the full site; everyone else only sees the coming-soon page.
     * Supports IPv4, IPv6, and CIDR ranges via Symfony's IpUtils.
     */
    private function ipAllowed(Request $request): bool
    {
        $allowed = ComingSoonAllowedIp::allowList();
        if ($allowed === []) {
            return false;
        }

        return IpUtils::checkIp((string) $request->ip(), $allowed);
    }
}
