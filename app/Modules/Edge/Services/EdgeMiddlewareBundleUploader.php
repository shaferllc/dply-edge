<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeDeliveryContext;
use App\Modules\Edge\Support\EdgeEffectiveCrons;
use App\Modules\Edge\Support\EdgeWorkerEntryWrapper;
use App\Modules\Edge\Support\FakeEdgeProvision;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Ships the per-deployment middleware Worker (produced by
 * {@see EdgeMiddlewareBundler}) into the Workers for Platforms
 * dispatch namespace so the platform Worker can `env.DISPATCHER.get()`
 * it before the R2 / origin lookup.
 *
 * Script naming: `dply-mw-{site-tail6}-{deploy-tail8}` — disambiguated
 * from SSR scripts (`dply-ssr-…`) so both can coexist on a single
 * deployment without name collisions. Same bindings as the SSR
 * uploader (HOST_MAP, ASSETS, DEPLOYMENT_ID, SITE_ID, STORAGE_PREFIX)
 * so middleware can read site state if it needs to.
 *
 * Pass-through contract (enforced in `packages/edge-worker/src/handler.ts`):
 *   - middleware default fetch returns a Response
 *   - status 204 with header `X-Dply-Middleware: continue` → platform
 *     Worker continues to R2/origin with the original request
 *   - anything else → response is served directly to the visitor
 */
class EdgeMiddlewareBundleUploader
{
    public function __construct(
        private readonly EdgeDeliveryContextResolver $contextResolver,
    ) {}

    public function uploadFromSidecar(EdgeDeployment $deployment, Site $site, ?string $sidecarPath): void
    {
        if (! is_string($sidecarPath) || ! is_file($sidecarPath)) {
            return;
        }

        $payload = json_decode((string) file_get_contents($sidecarPath), true);
        if (! is_array($payload) || ! is_array($payload['modules'] ?? null)) {
            return;
        }
        $entry = is_string($payload['entry_module'] ?? null) ? $payload['entry_module'] : 'middleware.js';
        /** @var array $modules */
        $modules = array_filter($payload['modules'], static fn ($value): bool => is_string($value));
        if ($modules === []) {
            return;
        }
        [$entry, $modules] = EdgeWorkerEntryWrapper::wrap($site, $entry, $modules);

        $scriptName = $this->scriptNameFor($site, $deployment);

        if (FakeEdgeProvision::enabled()) {
            $fakeRoot = (string) config('edge.fake.storage_root', storage_path('app/edge-fake'));
            File::ensureDirectoryExists($fakeRoot.'/mw-scripts/'.$scriptName);
            foreach ($modules as $name => $source) {
                File::put($fakeRoot.'/mw-scripts/'.$scriptName.'/'.$name, $source);
            }
            $this->persistScriptName($deployment, $scriptName, $payload);

            return;
        }

        $context = $this->contextResolver->forSite($site);
        if (! $context->supportsSsr()) {
            // Middleware reuses the SSR dispatch namespace — without
            // it we can't upload. Log + bail; the deploy continues
            // without middleware rather than failing.
            Log::warning('Skipping middleware upload — dispatch namespace not bootstrapped.', [
                'deployment_id' => (string) $deployment->id,
                'site_id' => (string) $site->id,
            ]);

            return;
        }

        $client = new EdgeCloudflareClient($context->accountId, $context->apiToken);
        $client->ensureDispatchNamespace($context->dispatchNamespaceName);
        $client->uploadDispatchScript(
            namespace: $context->dispatchNamespaceName,
            scriptName: $scriptName,
            entryModulePath: $entry,
            modules: $modules,
            bindings: $this->bindingsFor($deployment, $context),
            metaExtras: [
                'compatibility_date' => $context->ssrCompatibilityDate,
                'compatibility_flags' => EdgeWorkerEntryWrapper::compatibilityFlags($site, $context->ssrCompatibilityFlags),
                'tags' => ['dply-edge', 'dply-middleware', 'site:'.(string) $site->id],
            ],
        );

        $this->syncCronSchedules($client, $context->dispatchNamespaceName, $scriptName, $site, $deployment);

        $this->persistScriptName($deployment, $scriptName, $payload);
    }

    /**
     * Push the dply.yaml `crons:` block onto the freshly-uploaded
     * script. Pass an empty list to clear when a redeploy removes
     * crons. Wrapped in try/catch so a CF outage during schedule
     * upsert doesn't fail the whole deploy — the worker is live by
     * this point, only cron is missing.
     */
    private function syncCronSchedules(EdgeCloudflareClient $client, string $namespace, string $scriptName, Site $site, EdgeDeployment $deployment): void
    {
        $schedules = EdgeEffectiveCrons::schedulesFor($site, $deployment);

        try {
            $client->setDispatchScriptSchedules($namespace, $scriptName, $schedules);
        } catch (\Throwable $e) {
            Log::warning('Failed to sync cron schedules to middleware dispatch script', [
                'deployment_id' => (string) $deployment->id,
                'script' => $scriptName,
                'schedules' => $schedules,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function deleteAllForSite(Site $site): void
    {
        if (FakeEdgeProvision::enabled()) {
            return;
        }

        try {
            $context = $this->contextResolver->forSite($site);
        } catch (\Throwable) {
            return;
        }
        if (! $context->supportsSsr()) {
            return;
        }

        $client = new EdgeCloudflareClient($context->accountId, $context->apiToken);
        foreach ($site->edgeDeployments as $deployment) {
            $script = $this->scriptNameOnDeployment($deployment);
            if ($script === '') {
                continue;
            }
            try {
                $client->deleteDispatchScript($context->dispatchNamespaceName, $script);
            } catch (\Throwable $e) {
                Log::warning('Failed to delete middleware script during teardown', [
                    'deployment_id' => (string) $deployment->id,
                    'script' => $script,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Delete the middleware dispatch script for a single deployment
     * that's about to be pruned. Mirrors {@see deleteAllForSite} but
     * scoped to one row so {@see EdgeDeploymentPruner} can drop
     * scripts in lockstep with R2 artifacts. Best-effort — never
     * throws.
     */
    public function deleteScriptForDeployment(EdgeDeployment $deployment, Site $site): void
    {
        $script = $this->scriptNameOnDeployment($deployment);
        if ($script === '') {
            return;
        }
        if (FakeEdgeProvision::enabled()) {
            return;
        }

        try {
            $context = $this->contextResolver->forSite($site);
        } catch (\Throwable) {
            return;
        }
        if (! $context->supportsSsr()) {
            return;
        }

        try {
            (new EdgeCloudflareClient($context->accountId, $context->apiToken))
                ->deleteDispatchScript($context->dispatchNamespaceName, $script);
            $this->clearScriptName($deployment);
        } catch (\Throwable $e) {
            Log::warning('Failed to delete middleware dispatch script during prune', [
                'deployment_id' => (string) $deployment->id,
                'script' => $script,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function scriptNameFor(Site $site, EdgeDeployment $deployment): string
    {
        $siteTail = strtolower(substr((string) $site->id, -6));
        $deployTail = strtolower(substr((string) $deployment->id, -8));

        return 'dply-mw-'.$siteTail.'-'.$deployTail;
    }

    public function scriptNameOnDeployment(EdgeDeployment $deployment): string
    {
        $meta = is_array($deployment->meta) ? $deployment->meta : [];
        $mw = is_array($meta['middleware'] ?? null) ? $meta['middleware'] : [];

        return is_string($mw['script_name'] ?? null) ? trim($mw['script_name']) : '';
    }

    /**
     * @param  array<string, mixed>  $sidecarPayload
     */
    private function persistScriptName(EdgeDeployment $deployment, string $scriptName, array $sidecarPayload): void
    {
        $meta = is_array($deployment->meta) ? $deployment->meta : [];
        $mw = is_array($meta['middleware'] ?? null) ? $meta['middleware'] : [];
        $mw['script_name'] = $scriptName;
        $mw['source_path'] = is_string($sidecarPayload['source_path'] ?? null) ? $sidecarPayload['source_path'] : null;
        $mw['uploaded_at'] = now()->toIso8601String();
        $meta['middleware'] = $mw;
        $deployment->update(['meta' => $meta]);
    }

    /**
     * Clear the script-name marker after a successful dispatch delete
     * so a future re-publish doesn't believe the script is still live.
     */
    private function clearScriptName(EdgeDeployment $deployment): void
    {
        $meta = is_array($deployment->meta) ? $deployment->meta : [];
        $mw = is_array($meta['middleware'] ?? null) ? $meta['middleware'] : [];
        unset($mw['script_name']);
        $mw['deleted_at'] = now()->toIso8601String();
        $meta['middleware'] = $mw;
        $deployment->update(['meta' => $meta]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bindingsFor(EdgeDeployment $deployment, EdgeDeliveryContext $context): array
    {
        $bindings = [
            ['name' => 'HOST_MAP', 'type' => 'kv_namespace', 'namespace_id' => $context->kvNamespaceId],
            ['name' => 'ASSETS', 'type' => 'r2_bucket', 'bucket_name' => $context->r2Bucket],
            ['name' => 'DEPLOYMENT_ID', 'type' => 'plain_text', 'text' => (string) $deployment->id],
            ['name' => 'SITE_ID', 'type' => 'plain_text', 'text' => (string) $deployment->site_id],
            ['name' => 'STORAGE_PREFIX', 'type' => 'plain_text', 'text' => (string) $deployment->storage_prefix],
        ];

        if ($context->cacheKvNamespaceId !== '') {
            $bindings[] = ['name' => 'EDGE_CACHE', 'type' => 'kv_namespace', 'namespace_id' => $context->cacheKvNamespaceId];
        }

        // P10c — append user-declared bindings from dply.yaml so the
        // middleware can read its KV / R2 / D1 / Queues by name.
        foreach (app(EdgeRepoBindingTranslator::class)->bindingsFor($deployment) as $extra) {
            $bindings[] = $extra;
        }

        // Per-site env vars (P-env) — ship each as a `secret_text`
        // binding so middleware reads them via env.MY_VAR. RESERVED_NAMES
        // on the model already blocks the platform-injected names; we
        // re-check here defensively. Resolves the relation against the
        // deployment's site to avoid a separate query when one is loaded.
        $site = $deployment->site;
        if ($site !== null) {
            foreach (EdgeContainerConnections::omitAsleepRedis($site, app(EdgeProductionEnv::class)->forSite($site)) as $key => $value) {
                $bindings[] = [
                    'name' => $key,
                    'type' => 'secret_text',
                    'text' => $value,
                ];
            }
        }

        return $bindings;
    }
}
