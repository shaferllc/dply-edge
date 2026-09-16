<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgeDeployment;
use App\Models\Site;

/**
 * Flatten Edge product add-ons from site meta (+ optional repo_config)
 * into HOST_MAP fields for the Worker. Managed `dply_edge` only — BYO
 * delivery does not receive these keys.
 */
final class EdgeHostMapAddons
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(Site $site, ?EdgeDeployment $deployment = null): array
    {
        if (($site->edge_backend ?? '') !== 'dply_edge') {
            return [];
        }

        $meta = $site->edgeMeta();
        $payload = [];

        $turnstile = is_array($meta['turnstile'] ?? null) ? $meta['turnstile'] : [];
        if ((bool) ($turnstile['enabled'] ?? false)) {
            $siteKey = trim((string) ($turnstile['site_key'] ?? ''));
            $secret = trim((string) ($turnstile['secret_key'] ?? ''));
            if ($siteKey !== '' && $secret !== '') {
                $payload['turnstile'] = [
                    'enabled' => true,
                    'site_key' => $siteKey,
                    'secret_key' => $secret,
                    'mode' => in_array(($turnstile['mode'] ?? 'forms'), ['forms', 'all'], true)
                        ? (string) $turnstile['mode']
                        : 'forms',
                    'paths' => self::stringList($turnstile['paths'] ?? []),
                ];
            }
        }

        $rateLimit = is_array($meta['rate_limit'] ?? null) ? $meta['rate_limit'] : [];
        if ((bool) ($rateLimit['enabled'] ?? false)) {
            $rules = [];
            foreach (is_array($rateLimit['rules'] ?? null) ? $rateLimit['rules'] : [] as $rule) {
                if (! is_array($rule)) {
                    continue;
                }
                $path = trim((string) ($rule['path'] ?? '/*'));
                $limit = max(1, min(10_000, (int) ($rule['limit'] ?? 60)));
                $window = max(1, min(3600, (int) ($rule['window_seconds'] ?? 60)));
                $action = in_array(($rule['action'] ?? 'block'), ['block', 'challenge'], true)
                    ? (string) $rule['action']
                    : 'block';
                $rules[] = [
                    'path' => $path !== '' ? $path : '/*',
                    'limit' => $limit,
                    'window_seconds' => $window,
                    'action' => $action,
                ];
            }
            if ($rules !== []) {
                $payload['rate_limit'] = ['enabled' => true, 'rules' => $rules];
            }
        }

        $forms = EdgeEffectiveProductAddons::forms($site, $deployment);
        if ((bool) ($forms['enabled'] ?? false)) {
            $endpoints = [];
            foreach (is_array($forms['endpoints'] ?? null) ? $forms['endpoints'] : [] as $endpoint) {
                $path = trim((string) ($endpoint['path'] ?? ''));
                $toEmail = trim((string) ($endpoint['to_email'] ?? ''));
                if ($path === '' || $toEmail === '' || ! filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $endpoints[] = [
                    'path' => str_starts_with($path, '/') ? $path : '/'.$path,
                    'to_email' => $toEmail,
                    'honeypot' => trim((string) ($endpoint['honeypot'] ?? 'company')),
                    'require_turnstile' => (bool) ($endpoint['require_turnstile'] ?? true),
                ];
            }
            if ($endpoints !== []) {
                $ingestBase = rtrim((string) (config('edge.log_ingest.base_url')
                    ?: config('dply.public_app_url')
                    ?: config('app.url')), '/');
                $payload['forms'] = [
                    'enabled' => true,
                    'endpoints' => $endpoints,
                    'ingest_url' => $ingestBase !== ''
                        ? $ingestBase.'/hooks/edge/'.((string) $site->id).'/forms'
                        : null,
                    'ingest_key' => (string) config('edge.log_ingest.key', ''),
                ];
            }
        }

        $waiting = is_array($meta['waiting_room'] ?? null) ? $meta['waiting_room'] : [];
        if ((bool) ($waiting['enabled'] ?? false)) {
            $payload['waiting_room'] = [
                'enabled' => true,
                'total_active_users' => max(1, min(100_000, (int) ($waiting['total_active_users'] ?? 200))),
                'new_users_per_minute' => max(1, min(10_000, (int) ($waiting['new_users_per_minute'] ?? 20))),
                'session_duration_minutes' => max(1, min(1440, (int) ($waiting['session_duration_minutes'] ?? 30))),
                'paths' => self::stringList($waiting['paths'] ?? ['/*']),
            ];
        }

        $snippets = EdgeEffectiveProductAddons::snippets($site, $deployment);
        if ((bool) ($snippets['enabled'] ?? false)) {
            $items = [];
            foreach (is_array($snippets['items'] ?? null) ? $snippets['items'] : [] as $item) {
                $phase = in_array(($item['phase'] ?? ''), ['head', 'body'], true)
                    ? (string) $item['phase']
                    : null;
                $html = trim((string) ($item['html'] ?? ''));
                if ($phase === null || $html === '' || strlen($html) > 8000) {
                    continue;
                }
                $items[] = [
                    'name' => trim((string) ($item['name'] ?? 'snippet')),
                    'phase' => $phase,
                    'html' => $html,
                    'path' => trim((string) ($item['path'] ?? '/*')) ?: '/*',
                ];
            }
            if ($items !== []) {
                $payload['snippets'] = ['enabled' => true, 'items' => $items];
            }
        }

        $tags = EdgeEffectiveProductAddons::tags($site, $deployment);
        if ((bool) ($tags['enabled'] ?? false)) {
            $tools = [];
            foreach (is_array($tags['tools'] ?? null) ? $tags['tools'] : [] as $tool) {
                $normalized = is_array($tool) ? EdgeTagVendors::normalize($tool) : null;
                if ($normalized !== null) {
                    $tools[] = $normalized;
                }
            }
            $consentRequired = (bool) ($tags['consent_required'] ?? false);
            // Consent helper can publish without any script URLs yet — otherwise
            // an empty "analytics" row silently dropped the whole tags block.
            if ($tools !== [] || $consentRequired) {
                $payload['tags'] = [
                    'enabled' => true,
                    'consent_required' => $consentRequired,
                    'tools' => array_slice($tools, 0, 20),
                ];
            }
        }

        $jobs = is_array($meta['jobs'] ?? null) ? $meta['jobs'] : [];
        if ((bool) ($jobs['enabled'] ?? false)) {
            $payload['jobs'] = [
                'enabled' => true,
                'default_queue' => trim((string) ($jobs['default_queue'] ?? 'JOBS')),
            ];
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $trimmed = trim($item);
            if ($trimmed === '') {
                continue;
            }
            $out[] = str_starts_with($trimmed, '/') ? $trimmed : '/'.$trimmed;
        }

        return array_values(array_unique($out));
    }
}
