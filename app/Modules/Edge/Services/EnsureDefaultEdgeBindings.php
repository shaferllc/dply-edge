<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\Site;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Every Edge site gets a free per-site KV namespace in its own
 * Cloudflare account, exposed under `env.KV` so a user's worker can
 * call `await env.KV.get('foo')` without any config.
 *
 * Idempotent: the resolved CF namespace id is stashed on
 * `site.meta.edge.default_bindings.kv` so re-deploys are no-ops.
 * Errors are logged + the binding is skipped (returns null) — a CF
 * outage never fails the whole deploy.
 *
 * R2 / D1 are NOT auto-provisioned. Users who want them declare via
 * `wrangler.toml` (`[[r2_buckets]]`, `[[d1_databases]]`) and the
 * separate {@see EdgeBindingsAutoResolver} creates them on first
 * deploy.
 */
class EnsureDefaultEdgeBindings
{
    public function __construct(
        private readonly EdgeDeliveryContextResolver $contexts,
    ) {}

    /**
     * @return array{kv: ?string} resolved CF namespace id for the default KV
     */
    public function ensure(Site $site, ?\Closure $log = null): array
    {
        $meta = $site->edgeMeta();
        $existing = is_array($meta['default_bindings'] ?? null) ? $meta['default_bindings'] : [];

        $title = $this->kvTitleFor($site);
        $kvId = $this->ensureKv($site, $existing['kv'] ?? null, $title, $log);

        if ($kvId !== null) {
            $merged = array_merge($existing, ['kv' => $kvId]);
            $site->mergeEdgeMeta(['default_bindings' => $merged]);
            $site->save();
        }

        return ['kv' => $kvId];
    }

    private function ensureKv(Site $site, mixed $existingId, string $title, ?\Closure $log): ?string
    {
        if (is_string($existingId) && $existingId !== '') {
            return $existingId;
        }
        try {
            $client = $this->clientFor($site);
            $id = $client->kvNamespaceIdByTitle($title);
            if (is_string($id) && $id !== '') {
                if ($log) {
                    $log("[bindings] KV namespace '{$title}' already exists → env.KV");
                }

                return $id;
            }
            $created = $client->createKvNamespace($title);
            $id = is_string($created['id'] ?? null) ? (string) $created['id'] : null;
            if ($id !== null && $log) {
                $log("[bindings] Default KV namespace '{$title}' created → env.KV");
            }

            return $id;
        } catch (\Throwable $e) {
            Log::warning('default KV provision failed', ['title' => $title, 'error' => $e->getMessage()]);
            if ($log) {
                $log("[bindings] KV provision failed: {$e->getMessage()}");
            }

            return null;
        }
    }

    /** site-scoped KV title: short kebab + last 6 chars of ULID for uniqueness within the CF account. */
    private function kvTitleFor(Site $site): string
    {
        $base = Str::slug((string) ($site->name ?: 'site'));
        if ($base === '') {
            $base = 'site';
        }

        return substr($base, 0, 48).'-'.substr((string) $site->id, -6).'-kv';
    }

    private function clientFor(Site $site): EdgeCloudflareClient
    {
        $context = $this->contexts->forSite($site);

        return new EdgeCloudflareClient($context->accountId, $context->apiToken);
    }
}
