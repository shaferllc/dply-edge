<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Modules\Edge\Services\Config\EdgeRepoConfigLoader;

/**
 * Parses a pasted block of redirect rules into the dashboard redirect
 * shape ({from, to, status}) used by {@see EdgeEffectiveRouting}.
 *
 * Accepts the two formats an operator actually has on hand when moving
 * to Edge, decided per line by whether the line contains a comma:
 *
 *   Cloudflare Bulk Redirects CSV   source_url,target_url,status_code
 *   Netlify _redirects / dply.yaml  /from  /to  301
 *
 * `from` is normalised to a site-relative path: Cloudflare exports carry
 * the host (`example.com/blog/`, `https://example.com/blog/`) and dply
 * redirects are per-site and path-only, so scheme and host are stripped.
 * A bare host becomes `/`.
 *
 * Deliberately NOT supported: Cloudflare's extra flag columns
 * (preserve_query_string, include_subdomains, subpath_matching,
 * preserve_path_suffix) are ignored — the worker's glob matcher has no
 * equivalent, so translating them would guess at intent. Rules needing
 * subpath behaviour use an explicit `/*` + `:splat` pair, which both
 * input formats already express.
 */
final class EdgeRedirectImport
{
    /**
     * Ceiling on rules accepted from one paste. Dashboard redirects live
     * in the site's meta JSON and ship whole in the worker host map, so an
     * unbounded paste bloats both.
     *
     * ponytail: flat per-import cap, make it a plan allowance if anyone
     * legitimately needs more than this on one site.
     */
    public const MAX_RULES = 1000;

    /**
     * @return array{
     *     format: 'csv'|'list'|null,
     *     redirects: list<array{from: string, to: string, status: int}>,
     *     errors: list<string>
     * }
     */
    public static function parse(string $raw): array
    {
        $redirects = [];
        $errors = [];
        $sawComma = false;

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $i => $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $number = $i + 1;

            if (str_contains($line, ',')) {
                $sawComma = true;
                $fields = array_map(trim(...), explode(',', $line));
            } else {
                $fields = preg_split('/\s+/', $line) ?: [];
            }
            $fields = array_values(array_filter($fields, static fn (string $f): bool => $f !== ''));

            // CSV exports lead with a header row; skip it rather than
            // reporting it as a broken rule.
            $first = strtolower($fields[0] ?? '');
            if ($first === 'source_url' || $first === 'from') {
                continue;
            }

            if (count($fields) < 2) {
                $errors[] = __('Line :line: expected a source and a target.', ['line' => $number]);

                continue;
            }

            $from = self::toPath($fields[0]);
            if ($from === null) {
                $errors[] = __('Line :line: could not read a path from ":value".', ['line' => $number, 'value' => $fields[0]]);

                continue;
            }

            $status = 301;
            if (isset($fields[2])) {
                if (! ctype_digit($fields[2]) || ! in_array((int) $fields[2], EdgeRepoConfigLoader::ALLOWED_STATUS_CODES, true)) {
                    $errors[] = __('Line :line: status ":value" is not one of :allowed.', [
                        'line' => $number,
                        'value' => $fields[2],
                        'allowed' => implode(', ', EdgeRepoConfigLoader::ALLOWED_STATUS_CODES),
                    ]);

                    continue;
                }
                $status = (int) $fields[2];
            }

            if (count($redirects) >= self::MAX_RULES) {
                $errors[] = __('Stopped at :max rules — import the rest separately.', ['max' => self::MAX_RULES]);

                break;
            }

            $redirects[] = ['from' => $from, 'to' => $fields[1], 'status' => $status];
        }

        return [
            'format' => $redirects === [] && $errors === [] ? null : ($sawComma ? 'csv' : 'list'),
            'redirects' => $redirects,
            'errors' => $errors,
        ];
    }

    /**
     * Reduces a source to a site-relative path, dropping any scheme and
     * host. Returns null when nothing usable is left.
     */
    private static function toPath(string $source): ?string
    {
        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $source) ?? $source;

        if ($value === '') {
            return null;
        }

        if (! str_starts_with($value, '/')) {
            // Host-qualified (`example.com/blog`) — keep from the first slash.
            $slash = strpos($value, '/');
            $value = $slash === false ? '/' : substr($value, $slash);
        }

        return $value === '' ? null : $value;
    }
}
