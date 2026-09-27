<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Canonical list of request paths that only ever come from vulnerability
 * scanners and bots — WordPress fishing, leaked-secret probes, appliance/CVE
 * sweeps, crawler conventions. A 404 on any of these is pure noise.
 *
 * Lookout's HttpNotFoundReporter listens on RequestHandled and reports every
 * response whose status is exactly 404 as a handled "warning" event (see
 * vendor/lookout/tracing — there is no per-path ignore list, only the
 * all-or-nothing LOOKOUT_REPORT_HTTP_404 flag). Because the reporter fires
 * ONLY on status === 404, bootstrap/app.php renders a 410 Gone for these paths
 * instead — the bot is none the wiser, but Lookout skips it. Genuine 404s on
 * real app routes (a mistyped /livewire/* POST, a missing storage/site-logos
 * asset, etc.) still return 404 and are still reported.
 *
 * Keep this list CONSERVATIVE: every pattern is matched with Request::is()
 * (which decodes %2f-style escaped probes), so never add a glob that could
 * overlap a real dply route — prefer specific file names and unambiguous
 * vendor prefixes (wp-*, actuator, _profiler) over broad segments like
 * admin/* or api/* that map onto live routes.
 */
final class ScannerProbePaths
{
    /**
     * Path glob patterns (Request::is() syntax, no leading slash).
     *
     * @var list<string>
     */
    public const PATTERNS = [
        // WordPress fishing
        'wp-json', 'wp-json/*',
        'wp-admin', 'wp-admin/*',
        'wp-login.php',
        'wp-config*',
        '*/wp-includes/*', 'wp-includes/*',
        '*/wp-admin/*',
        '*/wlwmanifest.xml',

        // Leaked env / secret / config / DB-dump probes
        '*.env', 'env', 'env.bak', 'aws.env',
        'printenv*', 'live_env',
        'config/*',
        'secrets/*',
        'sendgrid_keys',
        'sftp-config.json',
        '*.sql',
        'index.php', 'index.php~',

        // Build-artifact / sourcemap noise (no sourcemaps shipped in prod)
        '*.map',
        'rollup.config.js',
        'chunk-*.js',
        'static/js/*',

        // Spring Boot Actuator + Symfony profiler sweeps
        'actuator', 'actuator/*',
        '_profiler', '_profiler/*',

        // Appliance / VPN / mail / CVE sweeps
        'remote/login',
        'vpn', 'vpn/*',
        'logon/*',
        'owa/*',
        'webui',
        'geoserver/*',
        'developmentserver/*',
        'ReportServer',
        'eventmanager',
        'dns-query',
        'sse',

        // .well-known convention probes (acme-challenge etc. are NOT listed)
        '.well-known/passkey-endpoints',
        '.well-known/traffic-advice',
        '.well-known/openid-configuration',

        // Server log file probes
        'storage/logs/*',

        // Crawler/scanner conventions we don't serve.
        // (robots.txt and ads.txt ARE served as static files under public/,
        // and security.txt and sitemap.xml are routes, so they're NOT listed here.)
        'version',
    ];

    /**
     * Is this request a known scanner/bot probe whose 404 we should silence?
     */
    public static function matches(Request $request): bool
    {
        if ($request->is(...self::PATTERNS)) {
            return true;
        }

        // Doubly-escaped probes (e.g. /%2fwp-config%2ebak, /%2fconfig%2fkeys%2ejson)
        // arrive with %2f-encoded slashes that Symfony leaves intact, so the decoded
        // path keeps a leading "/" Request::is() never strips. Normalise and re-test.
        $decoded = ltrim(rawurldecode($request->path()), '/');

        return $decoded !== '' && Str::is(self::PATTERNS, $decoded);
    }
}
