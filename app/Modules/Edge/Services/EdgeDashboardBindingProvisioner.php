<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\Site;
use App\Modules\Edge\Support\EdgeEffectiveBindings;
use RuntimeException;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;

/**
 * Creates (or adopts) the Cloudflare resource behind a dashboard-declared Edge
 * binding, in the site's own Cloudflare account.
 *
 * Split out of the Livewire component so the CF API surface lives with the rest
 * of the Edge services and can be exercised without a browser. Every method is
 * idempotent-ish: where Cloudflare offers a lookup (KV title, R2 bucket name) we
 * adopt the existing resource rather than erroring, so a user who clicks
 * "create" twice gets the same binding instead of a duplicate.
 *
 * Returns the *identifier* the Worker upload needs for that binding kind:
 *   kv -> namespace id, r2 -> bucket name, d1 -> database id, queue -> queue name
 */
class EdgeDashboardBindingProvisioner
{
    public function __construct(
        private readonly EdgeDeliveryContextResolver $contexts,
    ) {}

    /**
     * @param  string  $kind  one of EdgeEffectiveBindings::KINDS
     * @param  string  $label  the Cloudflare-side resource name to create/adopt
     * @return string the identifier to store as the binding value
     */
    public function create(Site $site, string $kind, string $label): string
    {
        if (! in_array($kind, EdgeEffectiveBindings::KINDS, true)) {
            throw new RuntimeException("Unknown binding kind: {$kind}");
        }

        $client = $this->clientFor($site);

        return match ($kind) {
            'kv' => $this->createKv($client, $label),
            'r2' => $this->createR2($client, $label),
            'd1' => $this->createD1($client, $label),
            'queue' => $this->createQueue($client, $label),
        };
    }

    private function createKv(EdgeCloudflareClient $client, string $label): string
    {
        $existing = $client->kvNamespaceIdByTitle($label);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $created = $client->createKvNamespace($label);
        $id = is_string($created['id'] ?? null) ? (string) $created['id'] : '';
        if ($id === '') {
            throw new RuntimeException("Cloudflare did not return an id for KV namespace '{$label}'.");
        }

        return $id;
    }

    private function createR2(EdgeCloudflareClient $client, string $label): string
    {
        // R2 bindings reference the bucket by NAME, so an existing bucket needs
        // no create call at all — adopt it.
        if (! $client->r2BucketExists($label)) {
            $client->createR2Bucket($label);
        }

        return $label;
    }

    private function createD1(EdgeCloudflareClient $client, string $label): string
    {
        foreach ($client->listD1Databases() as $db) {
            if (($db['name'] ?? null) === $label && is_string($db['uuid'] ?? null)) {
                return (string) $db['uuid'];
            }
        }

        $created = $client->createD1Database($label);
        $id = is_string($created['uuid'] ?? null) ? (string) $created['uuid'] : '';
        if ($id === '') {
            throw new RuntimeException("Cloudflare did not return a uuid for D1 database '{$label}'.");
        }

        return $id;
    }

    private function createQueue(EdgeCloudflareClient $client, string $label): string
    {
        foreach ($client->listQueues() as $queue) {
            if (($queue['queue_name'] ?? null) === $label) {
                return $label;
            }
        }

        $client->createQueue($label);

        return $label;
    }

    private function clientFor(Site $site): EdgeCloudflareClient
    {
        $context = $this->contexts->forSite($site);

        return new EdgeCloudflareClient($context->accountId, $context->apiToken);
    }
}
