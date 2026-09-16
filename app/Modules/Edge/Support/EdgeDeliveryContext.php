<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\ProviderCredential;
use RuntimeException;

/**
 * Cloudflare credentials + infra targets for a single Edge delivery scope
 * (platform config or an org-owned provider credential).
 */
final readonly class EdgeDeliveryContext
{
    /**
     * @param  list<string>  $workerRoutes
     * @param  list<string>  $ssrCompatibilityFlags
     */
    public function __construct(
        public string $backendKey,
        public string $accountId,
        public string $apiToken,
        public string $kvNamespaceId,
        public string $r2Bucket,
        public string $r2AccessKey,
        public string $r2Secret,
        public string $r2Endpoint,
        public string $r2KeyPrefix,
        public string $workerScriptName,
        public string $workerZoneName,
        public array $workerRoutes,
        public string $diskName,
        public ?string $providerCredentialId = null,
        /**
         * Optional KV namespace ID for the EDGE_CACHE binding (origin
         * response cache for hybrid sites). Empty string means the
         * Worker will be deployed without the binding and caching is a
         * no-op.
         */
        public string $cacheKvNamespaceId = '',
        /**
         * Workers for Platforms dispatch namespace name + id for
         * per-deployment SSR Worker scripts (Phase 4b). Both empty
         * when SSR isn't bootstrapped for this scope — static and
         * hybrid sites still work; SSR site creation is blocked.
         */
        public string $dispatchNamespaceName = '',
        public string $dispatchNamespaceId = '',
        public string $ssrCompatibilityDate = '2024-11-01',
        public array $ssrCompatibilityFlags = [],
    ) {}

    public static function platform(): self
    {
        $accountId = trim((string) config('edge.cloudflare.account_id'));
        $endpoint = EdgePlatformCredentials::r2Endpoint();

        return new self(
            backendKey: 'dply_edge',
            accountId: $accountId,
            apiToken: trim((string) config('edge.cloudflare.api_token')),
            kvNamespaceId: trim((string) config('edge.cloudflare.kv_namespace_id')),
            r2Bucket: trim((string) config('edge.r2.bucket')),
            r2AccessKey: trim((string) config('edge.r2.key')),
            r2Secret: trim((string) config('edge.r2.secret')),
            r2Endpoint: $endpoint,
            r2KeyPrefix: trim((string) config('edge.r2.key_prefix', 'edge/')),
            workerScriptName: trim((string) config('edge.cloudflare.worker_script_name', 'dply-edge')),
            workerZoneName: trim((string) config('edge.cloudflare.worker_zone_name')),
            workerRoutes: EdgePlatformCredentials::workerRoutes(),
            diskName: (string) config('edge.disk.name', 'edge_r2'),
            cacheKvNamespaceId: trim((string) config('edge.cloudflare.cache_kv_namespace_id', '')),
            dispatchNamespaceName: trim((string) config('edge.cloudflare.dispatch_namespace_name', '')),
            dispatchNamespaceId: trim((string) config('edge.cloudflare.dispatch_namespace_id', '')),
            ssrCompatibilityDate: trim((string) config('edge.cloudflare.ssr_script_compatibility_date', '2024-11-01')) ?: '2024-11-01',
            ssrCompatibilityFlags: array_values(array_filter(array_map(
                static fn ($flag) => is_string($flag) ? trim($flag) : '',
                (array) config('edge.cloudflare.ssr_script_compatibility_flags', []),
            ))),
        );
    }

    public function supportsSsr(): bool
    {
        // Namespace id may be resolved lazily via the Cloudflare API when
        // account + token are present — uploaders call ensureDispatchNamespace.
        return $this->dispatchNamespaceName !== ''
            && $this->accountId !== ''
            && $this->apiToken !== '';
    }

    public static function fromProviderCredential(ProviderCredential $credential): self
    {
        if ($credential->provider !== 'cloudflare') {
            throw new RuntimeException('Edge BYO delivery requires a Cloudflare provider credential.');
        }

        $edge = EdgeOrgCredentialConfig::read($credential);
        $accountId = trim((string) ($edge['account_id'] ?? ''));
        $kvId = trim((string) ($edge['kv_namespace_id'] ?? ''));
        $bucket = trim((string) ($edge['r2_bucket'] ?? ''));
        $accessKey = trim((string) ($edge['r2_access_key'] ?? ''));
        $secret = trim((string) ($edge['r2_secret'] ?? ''));
        $endpoint = trim((string) ($edge['r2_endpoint'] ?? ''));
        if ($endpoint === '' && $accountId !== '') {
            $endpoint = 'https://'.$accountId.'.r2.cloudflarestorage.com';
        }

        $token = $credential->getApiToken();
        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('Cloudflare API token is missing on the selected credential.');
        }

        $missing = [];
        if ($accountId === '') {
            $missing[] = 'account_id';
        }
        if ($kvId === '') {
            $missing[] = 'kv_namespace_id';
        }
        if ($bucket === '') {
            $missing[] = 'r2_bucket';
        }
        if ($accessKey === '') {
            $missing[] = 'r2_access_key';
        }
        if ($secret === '') {
            $missing[] = 'r2_secret';
        }
        if ($missing !== []) {
            throw new RuntimeException(
                'Cloudflare credential is not bootstrapped for Edge. Run: php artisan dply:edge:bootstrap-org '.$credential->id
                .' (missing: '.implode(', ', $missing).')',
            );
        }

        $routes = $edge['worker_routes'] ?? [];
        if (! is_array($routes)) {
            $routes = [];
        }

        return new self(
            backendKey: 'org_cloudflare',
            accountId: $accountId,
            apiToken: trim($token),
            kvNamespaceId: $kvId,
            r2Bucket: $bucket,
            r2AccessKey: $accessKey,
            r2Secret: $secret,
            r2Endpoint: rtrim($endpoint, '/'),
            r2KeyPrefix: trim((string) ($edge['r2_key_prefix'] ?? 'edge/')),
            workerScriptName: trim((string) ($edge['worker_script_name'] ?? 'dply-edge')),
            workerZoneName: trim((string) ($edge['worker_zone_name'] ?? '')),
            workerRoutes: array_values(array_filter(array_map(
                static fn ($route) => is_string($route) ? trim($route) : '',
                $routes,
            ))),
            diskName: 'edge_r2_org_'.$credential->id,
            providerCredentialId: (string) $credential->id,
            cacheKvNamespaceId: trim((string) ($edge['cache_kv_namespace_id'] ?? '')),
            dispatchNamespaceName: trim((string) ($edge['dispatch_namespace_name'] ?? '')),
            dispatchNamespaceId: trim((string) ($edge['dispatch_namespace_id'] ?? '')),
            ssrCompatibilityDate: trim((string) ($edge['ssr_script_compatibility_date'] ?? '2024-11-01')) ?: '2024-11-01',
            ssrCompatibilityFlags: array_values(array_filter(array_map(
                static fn ($flag) => is_string($flag) ? trim($flag) : '',
                (array) ($edge['ssr_script_compatibility_flags'] ?? []),
            ))),
        );
    }

    /**
     * @param  list<string>  $workerRoutes
     */
    public function withWorkerRoutes(array $workerRoutes): self
    {
        return new self(
            backendKey: $this->backendKey,
            accountId: $this->accountId,
            apiToken: $this->apiToken,
            kvNamespaceId: $this->kvNamespaceId,
            r2Bucket: $this->r2Bucket,
            r2AccessKey: $this->r2AccessKey,
            r2Secret: $this->r2Secret,
            r2Endpoint: $this->r2Endpoint,
            r2KeyPrefix: $this->r2KeyPrefix,
            workerScriptName: $this->workerScriptName,
            workerZoneName: $this->workerZoneName,
            workerRoutes: $workerRoutes,
            diskName: $this->diskName,
            providerCredentialId: $this->providerCredentialId,
            cacheKvNamespaceId: $this->cacheKvNamespaceId,
            dispatchNamespaceName: $this->dispatchNamespaceName,
            dispatchNamespaceId: $this->dispatchNamespaceId,
            ssrCompatibilityDate: $this->ssrCompatibilityDate,
            ssrCompatibilityFlags: $this->ssrCompatibilityFlags,
        );
    }

    public function isPlatform(): bool
    {
        return $this->backendKey === 'dply_edge';
    }

    public function isBootstrapped(): bool
    {
        return $this->accountId !== ''
            && $this->apiToken !== ''
            && $this->kvNamespaceId !== ''
            && $this->r2Bucket !== ''
            && $this->r2AccessKey !== ''
            && $this->r2Secret !== ''
            && $this->r2Endpoint !== '';
    }
}
