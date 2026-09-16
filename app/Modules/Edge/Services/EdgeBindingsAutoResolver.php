<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Facades\Log;

/**
 * Resolves dply.yaml `bindings:` titles to Cloudflare resource IDs,
 * creating the underlying CF resource on first use when needed.
 *
 * Each binding kind has slightly different "what's the right value to
 * upload" semantics:
 *
 *   - kv:   workers script API wants `namespace_id` (32-char hex)
 *   - r2:   wants `bucket_name` (the title the user gave it)
 *   - d1:   wants `id` (UUID)
 *   - queue: wants `queue_name`
 *
 * Pass-through rules: if the declared value already *looks* like the
 * id the CF API expects, we trust it (existing repos with pasted IDs
 * keep working). Otherwise we treat it as a title and look up / create.
 *
 * Opt-out via `bindings.auto_create: false` in dply.yaml — that path
 * returns the raw map and lets EdgeRepoBindingTranslator pass it
 * straight through (legacy "manual id" mode).
 */
class EdgeBindingsAutoResolver
{
    public function __construct(
        private readonly EdgeDeliveryContextResolver $contexts,
    ) {}

    /**
     * Returns a resolved bindings map (same shape as the input, but
     * with title values replaced by canonical CF IDs). Errors during
     * lookup/create are logged + the offending binding is dropped so a
     * single bad row never fails the whole deploy.
     *
     * @return array<string, array<string, string>>
     */
    public function resolve(Site $site, EdgeDeployment $deployment): array
    {
        $config = is_array($deployment->repo_config) ? $deployment->repo_config : [];
        $declared = is_array($config['bindings'] ?? null) ? $config['bindings'] : [];
        if ($declared === []) {
            return [];
        }

        // Opt-out: when `auto_create: false`, return as-is so the
        // translator treats values as final ids.
        $autoCreate = ! (
            is_array($declared['auto_create'] ?? null)
                ? false
                : ($declared['auto_create'] ?? true) === false
        );

        $resolved = [
            'kv' => $this->resolveBucket($site, $declared['kv'] ?? null, 'kv', $autoCreate),
            'r2' => $this->resolveBucket($site, $declared['r2'] ?? null, 'r2', $autoCreate),
            'd1' => $this->resolveBucket($site, $declared['d1'] ?? null, 'd1', $autoCreate),
            'queues' => $this->resolveBucket($site, $declared['queues'] ?? null, 'queues', $autoCreate),
        ];

        return array_filter($resolved, static fn (array $b): bool => $b !== []);
    }

    /**
     * @param  array<string, string>|null  $bucket
     * @return array<string, string>
     */
    private function resolveBucket(Site $site, mixed $bucket, string $kind, bool $autoCreate): array
    {
        if (! is_array($bucket) || $bucket === []) {
            return [];
        }

        $out = [];
        foreach ($bucket as $name => $value) {
            if (trim($value) === '') {
                continue;
            }
            $value = trim($value);
            if (! $autoCreate || $this->looksLikeResolvedId($kind, $value)) {
                $out[$name] = $value;

                continue;
            }

            try {
                $resolved = $this->lookupOrCreate($site, $kind, $value);
                if ($resolved !== null) {
                    $out[$name] = $resolved;
                }
            } catch (\Throwable $e) {
                Log::warning('Edge bindings auto-resolve failed', [
                    'site_id' => $site->id,
                    'kind' => $kind,
                    'binding' => $name,
                    'value' => $value,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $out;
    }

    private function looksLikeResolvedId(string $kind, string $value): bool
    {
        return match ($kind) {
            // KV namespace IDs are 32-char hex
            'kv' => preg_match('/^[a-f0-9]{32}$/i', $value) === 1,
            // D1 IDs are UUIDs
            'd1' => preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $value) === 1,
            // R2 and Queues use bucket/queue names as the binding value
            // (CF API echoes the same name back at upload time), so the
            // title IS the id. No auto-create needed once the resource
            // exists — but we still want to create it if missing.
            'r2', 'queues' => false,
            default => false,
        };
    }

    /** Looks up by name; creates if absent. Returns the canonical id (or name, for r2/queues). */
    private function lookupOrCreate(Site $site, string $kind, string $title): ?string
    {
        $client = $this->clientFor($site);

        return match ($kind) {
            'kv' => $this->lookupOrCreateKv($client, $title),
            'r2' => $this->lookupOrCreateR2($client, $title, $site),
            'd1' => $this->lookupOrCreateD1($client, $title, $site),
            'queues' => $this->lookupOrCreateQueue($client, $title),
            default => null,
        };
    }

    private function lookupOrCreateKv(EdgeCloudflareClient $client, string $title): ?string
    {
        $id = $client->kvNamespaceIdByTitle($title);
        if (is_string($id) && $id !== '') {
            return $id;
        }
        $created = $client->createKvNamespace($title);

        return is_string($created['id'] ?? null) ? (string) $created['id'] : null;
    }

    private function lookupOrCreateR2(EdgeCloudflareClient $client, string $name, Site $site): string
    {
        if ($client->r2BucketExists($name)) {
            return $name;
        }
        // Use the org's preferred residency on create, mirroring
        // EdgeOrgInfraBootstrapper. Defaults to null (CF picks).
        $region = (string) ($site->organization->edge_data_region ?? 'default');
        [$jur, $hint] = $this->mapRegion($region);
        $client->createR2Bucket($name, $hint, $jur);

        return $name;
    }

    private function lookupOrCreateD1(EdgeCloudflareClient $client, string $name, Site $site): ?string
    {
        foreach ($client->listD1Databases() as $db) {
            if (($db['name'] ?? null) === $name) {
                return is_string($db['uuid'] ?? null) ? (string) $db['uuid'] : null;
            }
        }
        $region = (string) ($site->organization->edge_data_region ?? 'wnam');
        [, $hint] = $this->mapRegion($region);
        $created = $client->createD1Database($name, $hint ?: 'wnam');

        return is_string($created['uuid'] ?? null) ? (string) $created['uuid'] : null;
    }

    private function lookupOrCreateQueue(EdgeCloudflareClient $client, string $name): string
    {
        foreach ($client->listQueues() as $q) {
            if (($q['queue_name'] ?? null) === $name) {
                return $name;
            }
        }
        $client->createQueue($name);

        return $name;
    }

    private function clientFor(Site $site): EdgeCloudflareClient
    {
        $context = $this->contexts->forSite($site);

        return new EdgeCloudflareClient($context->accountId, $context->apiToken);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function mapRegion(string $region): array
    {
        return match (strtolower(trim($region))) {
            'eu', 'eu-strict' => ['eu', 'weur'],
            'wnam' => [null, 'wnam'],
            'enam' => [null, 'enam'],
            'weur' => [null, 'weur'],
            'eeur' => [null, 'eeur'],
            'apac' => [null, 'apac'],
            'oc' => [null, 'oc'],
            default => [null, null],
        };
    }
}
