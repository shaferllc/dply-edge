<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\ProviderCredential;
use App\Modules\Edge\Support\EdgeOrgCredentialConfig;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;

class EdgeOrgInfraBootstrapper
{
    /**
     * @return array{bucket: string, kv_namespace_id: string, cache_kv_namespace_id: string, account_id: string}
     */
    public function bootstrap(
        ProviderCredential $credential,
        string $accountId,
        string $bucket,
        string $kvTitle,
        string $zoneName,
        string $workerScriptName,
        string $cacheKvTitle = EdgeDeliveryFeaturesEnsurer::CACHE_KV_TITLE,
        ?string $r2LocationHint = null,
        ?string $r2Jurisdiction = null,
    ): array {
        if ($credential->provider !== 'cloudflare') {
            throw new \InvalidArgumentException('Edge bootstrap requires a Cloudflare credential.');
        }

        $token = $credential->getApiToken();
        if (! is_string($token) || trim($token) === '') {
            throw new \RuntimeException('Cloudflare API token is missing.');
        }

        $client = new EdgeCloudflareClient($accountId, trim($token));
        $client->verifyToken();

        if (! $client->r2BucketExists($bucket)) {
            $client->createR2Bucket($bucket, $r2LocationHint, $r2Jurisdiction);
        }

        $kvId = $client->kvNamespaceIdByTitle($kvTitle);
        if ($kvId === null) {
            $created = $client->createKvNamespace($kvTitle);
            $kvId = is_string($created['id'] ?? null) ? $created['id'] : '';
        }

        if ($kvId === '') {
            throw new \RuntimeException('Could not resolve KV namespace id after bootstrap.');
        }

        $cacheKvId = $client->kvNamespaceIdByTitle($cacheKvTitle);
        if ($cacheKvId === null) {
            $createdCache = $client->createKvNamespace($cacheKvTitle);
            $cacheKvId = is_string($createdCache['id'] ?? null) ? $createdCache['id'] : '';
        }

        if ($cacheKvId === '') {
            throw new \RuntimeException('Could not resolve cache KV namespace id after bootstrap.');
        }

        $routePattern = $zoneName !== '' ? '*.'.$zoneName.'/*' : '';

        EdgeOrgCredentialConfig::merge($credential, [
            'account_id' => $accountId,
            'r2_bucket' => $bucket,
            'kv_namespace_id' => $kvId,
            'cache_kv_namespace_id' => $cacheKvId,
            'worker_script_name' => $workerScriptName,
            'worker_zone_name' => $zoneName,
            'worker_routes' => $routePattern !== '' ? [$routePattern] : [],
            'r2_key_prefix' => 'edge/',
            'bootstrapped_at' => now()->toIso8601String(),
        ]);

        return [
            'bucket' => $bucket,
            'kv_namespace_id' => $kvId,
            'cache_kv_namespace_id' => $cacheKvId,
            'account_id' => $accountId,
        ];
    }
}
