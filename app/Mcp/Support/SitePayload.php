<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Models\Site;

/**
 * The one shape MCP tools and resources return for an Edge app.
 */
final class SitePayload
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Site $site): array
    {
        return [
            'id' => $site->id,
            'slug' => $site->slug,
            'name' => $site->name,
            'runtime_mode' => (string) ($site->edgeMeta()['runtime_mode'] ?? 'static'),
            'status' => $site->status,
            'live_url' => $site->edgeLiveUrl(),
            'last_deploy_at' => $site->last_deploy_at?->toIso8601String(),
            'created_at' => $site->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Site $site): array
    {
        $source = is_array($site->edgeMeta()['source'] ?? null) ? $site->edgeMeta()['source'] : [];
        $domains = $site->edgeMeta()['routing']['custom_domains'] ?? [];

        return self::summary($site) + [
            'repository' => $source['repo'] ?? null,
            'branch' => $source['branch'] ?? null,
            'custom_domains' => collect(is_array($domains) ? $domains : [])
                ->map(fn ($info, $hostname): array => [
                    'hostname' => (string) $hostname,
                    'dns_status' => is_array($info) ? ($info['dns_status'] ?? null) : null,
                    'ssl_status' => is_array($info) ? ($info['ssl_status'] ?? null) : null,
                ])->values()->all(),
        ];
    }
}
