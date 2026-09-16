<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgeDeployment;
use App\Models\Site;

/**
 * Resolves the effective routing config (redirects + rewrites +
 * header rules) by merging dply.yaml-declared rules with dashboard
 * overrides. Repo rules are emitted first, then dashboard rules; the
 * worker applies them in declaration order so dashboard appends
 * effectively run AFTER the repo's set.
 *
 * Use this everywhere downstream consumers want the "live" routing
 * rules (worker host map, generated dply.yaml, UI display).
 */
final class EdgeEffectiveRouting
{
    /**
     * @return array{
     *     redirects: list<array{from: string, to: string, status: int, source: 'repo'|'dashboard'}>,
     *     rewrites: list<array{from: string, to: string, source: 'repo'|'dashboard'}>,
     *     headers: list<array{for: string, values: array<string, string>, source: 'repo'|'dashboard'}>,
     *     sources: array{repo: bool, dashboard: bool}
     * }
     */
    public static function for(Site $site, ?EdgeDeployment $deployment): array
    {
        $repoConfig = is_array($deployment?->repo_config) ? $deployment->repo_config : [];
        $repoRedirects = is_array($repoConfig['redirects'] ?? null) ? $repoConfig['redirects'] : [];
        $repoRewrites = is_array($repoConfig['rewrites'] ?? null) ? $repoConfig['rewrites'] : [];
        $repoHeaders = is_array($repoConfig['headers'] ?? null) ? $repoConfig['headers'] : [];

        $overrides = is_array($site->edgeMeta()['routing_overrides'] ?? null) ? $site->edgeMeta()['routing_overrides'] : [];
        $dashRedirects = is_array($overrides['redirects'] ?? null) ? $overrides['redirects'] : [];
        $dashRewrites = is_array($overrides['rewrites'] ?? null) ? $overrides['rewrites'] : [];
        $dashHeaders = is_array($overrides['headers'] ?? null) ? $overrides['headers'] : [];

        $redirects = array_merge(
            self::tagRedirects($repoRedirects, 'repo'),
            self::tagRedirects($dashRedirects, 'dashboard'),
        );
        $rewrites = array_merge(
            self::tagRewrites($repoRewrites, 'repo'),
            self::tagRewrites($dashRewrites, 'dashboard'),
        );
        $headers = array_merge(
            self::tagHeaders($repoHeaders, 'repo'),
            self::tagHeaders($dashHeaders, 'dashboard'),
        );

        return [
            'redirects' => $redirects,
            'rewrites' => $rewrites,
            'headers' => $headers,
            'sources' => [
                'repo' => $repoRedirects !== [] || $repoRewrites !== [] || $repoHeaders !== [],
                'dashboard' => $dashRedirects !== [] || $dashRewrites !== [] || $dashHeaders !== [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return list<array{from: string, to: string, status: int, source: 'repo'|'dashboard'}>
     */
    private static function tagRedirects(array $rules, string $source): array
    {
        $out = [];
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $from = is_string($rule['from'] ?? null) ? trim((string) $rule['from']) : '';
            $to = is_string($rule['to'] ?? null) ? trim((string) $rule['to']) : '';
            if ($from === '' || $to === '') {
                continue;
            }
            $status = (int) ($rule['status'] ?? 301);
            if ($status < 300 || $status > 399) {
                $status = 301;
            }
            $out[] = ['from' => $from, 'to' => $to, 'status' => $status, 'source' => $source];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return list<array{from: string, to: string, source: 'repo'|'dashboard'}>
     */
    private static function tagRewrites(array $rules, string $source): array
    {
        $out = [];
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $from = is_string($rule['from'] ?? null) ? trim((string) $rule['from']) : '';
            $to = is_string($rule['to'] ?? null) ? trim((string) $rule['to']) : '';
            if ($from === '' || $to === '') {
                continue;
            }
            $out[] = ['from' => $from, 'to' => $to, 'source' => $source];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return list<array{for: string, values: array<string, string>, source: 'repo'|'dashboard'}>
     */
    private static function tagHeaders(array $rules, string $source): array
    {
        $out = [];
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $for = is_string($rule['for'] ?? null) ? trim((string) $rule['for']) : '';
            $values = is_array($rule['values'] ?? null) ? $rule['values'] : [];
            $clean = [];
            foreach ($values as $name => $value) {
                if (is_string($name) && is_string($value) && trim($name) !== '') {
                    $clean[$name] = $value;
                }
            }
            if ($for === '' || $clean === []) {
                continue;
            }
            $out[] = ['for' => $for, 'values' => $clean, 'source' => $source];
        }

        return $out;
    }
}
