<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeEffectiveBindings;
use App\Modules\Edge\Support\FakeEdgeProvision;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;

/**
 * Builds the list of Cloudflare binding descriptors uploaded with
 * each Edge worker script.
 *
 * Two sources:
 *   1. The per-site default `env.KV` namespace (always injected, lazily
 *      provisioned in the user's CF account by EnsureDefaultEdgeBindings)
 *   2. Bindings declared in the repo's `wrangler.toml` (discovered at
 *      build time by WranglerBindingsExtractor; values can be titles
 *      that EdgeBindingsAutoResolver creates on first use)
 *
 * Declared bindings override the default if they collide on name.
 * Used by both {@see EdgeSsrBundleUploader} and
 * {@see EdgeMiddlewareBundleUploader}.
 */
class EdgeRepoBindingTranslator
{
    /** Names the platform Worker already injects — repo + defaults are dropped if they collide. */
    private const RESERVED_NAMES = EdgeEffectiveBindings::RESERVED_NAMES;

    public function __construct(
        private readonly EdgeBindingsAutoResolver $autoResolver,
        private readonly EnsureDefaultEdgeBindings $defaultBindings,
        private readonly EdgeDplyResourceResolver $dplyResources,
    ) {}

    /**
     * State (durable_object) bindings point at the site's stable dply-state
     * script, uploaded here on first use.
     *
     * @return list<array<string, mixed>>
     */
    private function stateBindings(Site $site): array
    {
        if (! EdgeStateScript::needed($site) || FakeEdgeProvision::enabled()) {
            return [];
        }
        $context = app(EdgeDeliveryContextResolver::class)->forSite($site);
        $script = app(EdgeStateScript::class)->ensure(
            $site,
            new EdgeCloudflareClient($context->accountId, $context->apiToken),
            $context->accountId,
            $context->dispatchNamespaceName,
            $context->ssrCompatibilityDate,
        );
        $out = [];
        foreach (EdgeContainerConnections::for($site) as $connection) {
            if ($connection['kind'] === 'durable_object' && ! $connection['asleep']) {
                $out[] = [
                    'name' => $connection['name'],
                    'type' => 'durable_object_namespace',
                    'class_name' => EdgeStateScript::CLASS_NAME,
                    'script_name' => $script,
                    'dispatch_namespace' => $context->dispatchNamespaceName,
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function bindingsFor(EdgeDeployment $deployment): array
    {
        $config = is_array($deployment->repo_config) ? $deployment->repo_config : [];
        $site = $deployment->site;

        // Resolve wrangler-declared bindings (titles → CF IDs, with
        // auto-create on first use). The dply.yaml `bindings:` schema
        // is no longer parsed; everything declarative comes through
        // wrangler.toml via WranglerBindingsExtractor at build time.
        $declared = [];
        if (is_array($config['bindings'] ?? null) && $site instanceof Site) {
            $declared = $this->autoResolver->resolve($site, $deployment) ?: $config['bindings'];
        } elseif (is_array($config['bindings'] ?? null)) {
            $declared = $config['bindings'];
        }

        $declaredNames = $this->collectDeclaredNames($declared);

        $out = [];

        // env.KV — per-site default. Skipped when declared overrides it
        // or when the platform reserves the name (defensive).
        $defaultKvId = $site instanceof Site ? $this->defaultBindings->ensure($site)['kv'] : null;
        if (is_string($defaultKvId) && ! in_array('KV', $declaredNames, true)) {
            $out[] = ['name' => 'KV', 'type' => 'kv_namespace', 'namespace_id' => $defaultKvId];
        }

        foreach ((array) ($declared['kv'] ?? []) as $name => $namespaceId) {
            if (! $this->isUsableName($name) || ! is_string($namespaceId)) {
                continue;
            }
            $out[] = ['name' => $name, 'type' => 'kv_namespace', 'namespace_id' => $namespaceId];
        }
        foreach ((array) ($declared['r2'] ?? []) as $name => $bucketName) {
            if (! $this->isUsableName($name) || ! is_string($bucketName)) {
                continue;
            }
            $out[] = ['name' => $name, 'type' => 'r2_bucket', 'bucket_name' => $bucketName];
        }
        foreach ((array) ($declared['d1'] ?? []) as $name => $databaseId) {
            if (! $this->isUsableName($name) || ! is_string($databaseId)) {
                continue;
            }
            $out[] = ['name' => $name, 'type' => 'd1', 'id' => $databaseId];
        }
        foreach ((array) ($declared['queues'] ?? []) as $name => $queueName) {
            if (! $this->isUsableName($name) || ! is_string($queueName)) {
                continue;
            }
            $out[] = ['name' => $name, 'type' => 'queue', 'queue_name' => $queueName];
        }

        // Resources-page connections (site.meta.edge.connections). Purely
        // additive, exactly like EdgeEffectiveCrons: anything already bound by
        // wrangler.toml, the default env.KV, or a reserved platform name wins.
        // Two bindings sharing a name would make Cloudflare reject the script
        // upload and fail an otherwise-good deploy.
        if ($site instanceof Site) {
            $used = array_column($out, 'name');
            $queues = array_map(
                static fn (array $row): array => ['name' => $row['name'], 'type' => 'queue', 'queue_name' => $row['value']],
                array_values(array_filter(EdgeEffectiveBindings::dashboardOverrides($site), static fn (array $row): bool => $row['kind'] === 'queue')),
            );
            $env = array_keys(EdgeContainerConnections::omitAsleepRedis($site, app(EdgeProductionEnv::class)->forSite($site)));
            $used = array_merge($used, $env);
            // The platform Worker proves it is the one delivering a batch.
            $queueToken = $queues !== [] ? [['name' => 'DPLY_QUEUE_TOKEN', 'type' => 'secret_text', 'text' => EdgeQueueConsumers::token($site)]] : [];
            foreach ([...EdgeContainerConnections::workerBindings($site), ...$queues, ...$queueToken, ...$this->stateBindings($site)] as $binding) {
                if (! $this->isUsableName($binding['name']) || in_array($binding['name'], $used, true)) {
                    continue;
                }
                $used[] = $binding['name'];
                $out[] = $binding;
            }
        }

        // bindings.dply — native references to sibling dply-managed resources
        // (Postgres/Redis/queue/storage in the same workspace). Resolved FRESH
        // here at upload time — never snapshotted into repo_config — so the
        // connection secret is always current and never persisted in cleartext.
        // Only publicly-reachable resources yield a secret_text value; private
        // VM resources resolve to no secret (they route via hybrid origin).
        $dplyRefs = is_array($config['bindings']['dply'] ?? null) ? $config['bindings']['dply'] : [];
        if ($dplyRefs !== [] && $site instanceof Site) {
            $used = array_column($out, 'name');
            foreach ($this->dplyResources->resolve($site, $dplyRefs) as $resolved) {
                $name = $resolved['env_name'];
                if ($resolved['status'] !== 'public' || $resolved['value'] === null) {
                    continue;
                }
                if (! $this->isUsableName($name) || in_array($name, $used, true)) {
                    continue;
                }
                $used[] = $name;
                $out[] = ['name' => $name, 'type' => 'secret_text', 'text' => $resolved['value']];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, string>>  $declared
     */
    private function collectDeclaredNames(array $declared): array
    {
        $names = [];
        foreach (['kv', 'r2', 'd1', 'queues'] as $kind) {
            $bucket = $declared[$kind] ?? null;
            if (! is_array($bucket)) {
                continue;
            }
            foreach (array_keys($bucket) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function isUsableName(mixed $name): bool
    {
        return is_string($name)
            && $name !== ''
            && ! in_array($name, self::RESERVED_NAMES, true);
    }
}
