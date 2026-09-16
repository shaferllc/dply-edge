<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeDeliveryContext;
use App\Modules\Edge\Support\EdgeEffectiveCrons;
use App\Modules\Edge\Support\FakeEdgeProvision;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Ships a per-deployment SSR Worker bundle (produced by EdgeBuildRunner
 * in ssr mode) into the platform's Workers for Platforms dispatch
 * namespace. The script name embeds the site + deployment id so each
 * deploy gets its own immutable script and the platform Worker can
 * route traffic to a specific deployment without redeploys.
 *
 * Bindings handed to the per-deployment script:
 *   - ASSETS:    R2 bucket bound at the deployment's storage_prefix
 *   - HOST_MAP:  KV namespace for hostname → routing payload
 *   - DEPLOYMENT_ID / SITE_ID / STORAGE_PREFIX: plain-text identifiers
 *     the SSR runtime can read for asset lookups + logging.
 *
 * Script naming: `dply-ssr-{site-tail6}-{deploy-tail8}`. Stays under
 * Cloudflare's 64-char script name limit and is human-readable in the
 * CF dashboard / `wrangler dispatch` listings.
 */
class EdgeSsrBundleUploader
{
    public function __construct(
        private readonly EdgeDeliveryContextResolver $contextResolver,
    ) {}

    /**
     * Upload the bundle described by $sidecarPath (written by
     * BuildEdgeSiteJob) and record the resulting script name on the
     * deployment row. No-op when the runtime_mode isn't ssr or the
     * sidecar is missing.
     */
    public function uploadFromSidecar(EdgeDeployment $deployment, Site $site, ?string $sidecarPath): void
    {
        if (($site->edgeMeta()['runtime_mode'] ?? 'static') !== 'ssr') {
            return;
        }
        if (! is_string($sidecarPath) || ! is_file($sidecarPath)) {
            throw new RuntimeException('SSR bundle sidecar is missing — the build may have failed before producing worker.js.');
        }

        $payload = json_decode((string) file_get_contents($sidecarPath), true);
        if (! is_array($payload) || ! is_array($payload['modules'] ?? null)) {
            throw new RuntimeException('SSR bundle sidecar is malformed.');
        }
        $entry = is_string($payload['entry_module'] ?? null) ? $payload['entry_module'] : 'worker.js';
        /** @var array $modules */
        $modules = array_filter($payload['modules'], static fn ($value): bool => is_string($value));
        if ($modules === []) {
            throw new RuntimeException('SSR bundle sidecar has no module sources.');
        }

        $scriptName = $this->scriptNameFor($site, $deployment);

        if (FakeEdgeProvision::enabled()) {
            // Fake mode: persist the bundle to disk so operators can
            // inspect it, but skip the real CF API call.
            $fakeRoot = (string) config('edge.fake.storage_root', storage_path('app/edge-fake'));
            File::ensureDirectoryExists($fakeRoot.'/ssr-scripts/'.$scriptName);
            foreach ($modules as $name => $source) {
                File::put($fakeRoot.'/ssr-scripts/'.$scriptName.'/'.$name, $source);
            }
            $this->persistScriptName($deployment, $scriptName);
            Log::info('SSR script uploaded (fake mode)', [
                'deployment_id' => (string) $deployment->id,
                'script' => $scriptName,
                'modules' => array_keys($modules),
            ]);

            return;
        }

        $context = $this->contextResolver->forSite($site);
        if (! $context->supportsSsr()) {
            throw new RuntimeException('Dispatch namespace is not configured for this Edge scope. Set DPLY_EDGE_CF_ACCOUNT_ID + DPLY_EDGE_CF_API_TOKEN (Workers for Platforms), or run `php artisan dply:edge:infra:bootstrap`.');
        }

        $client = $this->clientFor($context);
        // Ensure the namespace exists even when only the name is configured
        // (id may be absent from .env until first SSR deploy).
        $client->ensureDispatchNamespace($context->dispatchNamespaceName);
        $client->uploadDispatchScript(
            namespace: $context->dispatchNamespaceName,
            scriptName: $scriptName,
            entryModulePath: $entry,
            modules: $modules,
            bindings: $this->bindingsFor($deployment, $context),
            metaExtras: [
                'compatibility_date' => $context->ssrCompatibilityDate,
                'compatibility_flags' => $context->ssrCompatibilityFlags,
                'tags' => ['dply-edge', 'site:'.(string) $site->id],
            ],
        );

        $this->syncCronSchedules($client, $context->dispatchNamespaceName, $scriptName, $site, $deployment);

        $this->persistScriptName($deployment, $scriptName);
    }

    /**
     * Push the dply.yaml `crons:` block onto the freshly-uploaded SSR
     * script. Empty list clears schedules when a redeploy removes the
     * crons block. Best-effort — never throws.
     */
    private function syncCronSchedules(EdgeCloudflareClient $client, string $namespace, string $scriptName, Site $site, EdgeDeployment $deployment): void
    {
        $schedules = EdgeEffectiveCrons::schedulesFor($site, $deployment);

        try {
            $client->setDispatchScriptSchedules($namespace, $scriptName, $schedules);
        } catch (\Throwable $e) {
            Log::warning('Failed to sync cron schedules to SSR dispatch script', [
                'deployment_id' => (string) $deployment->id,
                'script' => $scriptName,
                'schedules' => $schedules,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete every per-deployment SSR script ever uploaded for $site.
     * Called from site teardown — leaving scripts behind would keep
     * the platform Worker pointing at dead routing entries and could
     * silently consume dispatch namespace quota.
     */
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

        $client = $this->clientFor($context);
        foreach ($site->edgeDeployments as $deployment) {
            $script = $this->scriptNameOnDeployment($deployment);
            if ($script === '') {
                continue;
            }
            try {
                $client->deleteDispatchScript($context->dispatchNamespaceName, $script);
            } catch (\Throwable $e) {
                Log::warning('Failed to delete dispatch script during teardown', [
                    'deployment_id' => (string) $deployment->id,
                    'script' => $script,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Delete the SSR dispatch script for a single deployment that's
     * about to be pruned. Mirrors {@see deleteAllForSite} but scoped
     * to one row so {@see EdgeDeploymentPruner} can drop scripts in
     * lockstep with R2 artifacts. Best-effort — never throws.
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
            $this->clientFor($context)
                ->deleteDispatchScript($context->dispatchNamespaceName, $script);
            $this->clearScriptName($deployment);
        } catch (\Throwable $e) {
            Log::warning('Failed to delete SSR dispatch script during prune', [
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

        return 'dply-ssr-'.$siteTail.'-'.$deployTail;
    }

    public function scriptNameOnDeployment(EdgeDeployment $deployment): string
    {
        $meta = is_array($deployment->meta) ? $deployment->meta : [];
        $ssr = is_array($meta['ssr'] ?? null) ? $meta['ssr'] : [];

        return is_string($ssr['script_name'] ?? null) ? trim($ssr['script_name']) : '';
    }

    private function persistScriptName(EdgeDeployment $deployment, string $scriptName): void
    {
        $meta = is_array($deployment->meta) ? $deployment->meta : [];
        $ssr = is_array($meta['ssr'] ?? null) ? $meta['ssr'] : [];
        $ssr['script_name'] = $scriptName;
        $ssr['uploaded_at'] = now()->toIso8601String();
        $meta['ssr'] = $ssr;
        $deployment->update(['meta' => $meta]);
    }

    /**
     * Clear the script-name marker after a successful dispatch delete
     * so a future re-publish doesn't believe the script is still live.
     */
    private function clearScriptName(EdgeDeployment $deployment): void
    {
        $meta = is_array($deployment->meta) ? $deployment->meta : [];
        $ssr = is_array($meta['ssr'] ?? null) ? $meta['ssr'] : [];
        unset($ssr['script_name']);
        $ssr['deleted_at'] = now()->toIso8601String();
        $meta['ssr'] = $ssr;
        $deployment->update(['meta' => $meta]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bindingsFor(EdgeDeployment $deployment, EdgeDeliveryContext $context): array
    {
        $bindings = [
            [
                'name' => 'HOST_MAP',
                'type' => 'kv_namespace',
                'namespace_id' => $context->kvNamespaceId,
            ],
            [
                'name' => 'ASSETS',
                'type' => 'r2_bucket',
                'bucket_name' => $context->r2Bucket,
            ],
            [
                'name' => 'DEPLOYMENT_ID',
                'type' => 'plain_text',
                'text' => (string) $deployment->id,
            ],
            [
                'name' => 'SITE_ID',
                'type' => 'plain_text',
                'text' => (string) $deployment->site_id,
            ],
            [
                'name' => 'STORAGE_PREFIX',
                'type' => 'plain_text',
                'text' => (string) $deployment->storage_prefix,
            ],
        ];

        if ($context->cacheKvNamespaceId !== '') {
            $bindings[] = [
                'name' => 'EDGE_CACHE',
                'type' => 'kv_namespace',
                'namespace_id' => $context->cacheKvNamespaceId,
            ];
        }

        // P10c — append user-declared bindings from dply.yaml so SSR
        // code can read from its KV / R2 / D1 / Queues by name.
        foreach (app(EdgeRepoBindingTranslator::class)->bindingsFor($deployment) as $extra) {
            $bindings[] = $extra;
        }

        // Per-site env vars (P-env) — ship each as a `secret_text`
        // binding so the SSR runtime reads them via env.MY_VAR.
        // RESERVED_NAMES on the model blocks platform-injected names.
        $site = $deployment->site;
        if ($site !== null) {
            foreach (app(EdgeProductionEnv::class)->forSite($site) as $key => $value) {
                $bindings[] = [
                    'name' => $key,
                    'type' => 'secret_text',
                    'text' => $value,
                ];
            }
        }

        return $bindings;
    }

    private function clientFor(EdgeDeliveryContext $context): EdgeCloudflareClient
    {
        return new EdgeCloudflareClient($context->accountId, $context->apiToken);
    }
}
