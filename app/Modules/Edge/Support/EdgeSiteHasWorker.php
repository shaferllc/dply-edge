<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgeDeployment;
use App\Models\Site;

/**
 * Whether an Edge site has a per-deployment Worker that can receive
 * bindings / cron triggers / Jobs `env` access.
 *
 * True for Worker-native SSR, or for static/hybrid sites that shipped
 * a middleware script on a deploy. Pure static R2 delivery has no
 * Worker of its own.
 */
final class EdgeSiteHasWorker
{
    public static function for(Site $site, ?EdgeDeployment $deployment = null): bool
    {
        $runtimeMode = (string) ($site->edgeMeta()['runtime_mode'] ?? 'static');
        // Container sites always have their fronting Worker (queues bind there).
        if (in_array($runtimeMode, ['ssr', 'container'], true)) {
            return true;
        }

        $deployment ??= self::latestConfigDeployment($site);
        $meta = is_array($deployment?->meta) ? $deployment->meta : [];
        $middleware = is_array($meta['middleware'] ?? null) ? $meta['middleware'] : [];

        return is_string($middleware['script_name'] ?? null) && trim($middleware['script_name']) !== '';
    }

    private static function latestConfigDeployment(Site $site): ?EdgeDeployment
    {
        if (! $site->exists) {
            return null;
        }

        return EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first()
            ?: EdgeDeployment::query()
                ->where('site_id', $site->id)
                ->whereNotNull('repo_config')
                ->latest('id')
                ->first();
    }
}
