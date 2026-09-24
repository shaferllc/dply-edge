<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeDeliveryContextResolver;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Free orgs have no card, so container traffic past the usage credit is a
 * loss: Cloudflare keeps billing while the container is awake. Once the
 * credit is used, write a KV flag the edge worker checks before it starts
 * the container. Paid orgs are never flagged — their overage is invoiced.
 */
final class StarterTrafficGate
{
    public const KEY_PREFIX = 'container-pause:';

    public function __construct(
        private StarterUsageBudget $budget,
        private EdgeHostMapPublisher $hostMap,
        private EdgeDeliveryContextResolver $contexts,
    ) {}

    public function syncAll(): void
    {
        $organizationIds = Site::query()
            ->where('meta->edge->runtime_mode', 'container')
            ->where(function ($query): void {
                $query->whereNull('edge_backend')->orWhere('edge_backend', '!=', 'org_cloudflare');
            })
            ->distinct()
            ->pluck('organization_id');

        foreach ($organizationIds as $organizationId) {
            $organization = Organization::query()->find($organizationId);
            if ($organization !== null) {
                $this->syncOrganization($organization);
            }
        }
    }

    public function syncOrganization(Organization $organization): void
    {
        $pause = $this->budget->status($organization)['exhausted'];

        Site::query()
            ->where('organization_id', $organization->id)
            ->where('meta->edge->runtime_mode', 'container')
            ->where(function ($query): void {
                $query->whereNull('edge_backend')->orWhere('edge_backend', '!=', 'org_cloudflare');
            })
            ->each(function (Site $site) use ($pause): void {
                $this->write($site, $pause);
            });
    }

    private function write(Site $site, bool $pause): void
    {
        try {
            $context = $this->contexts->forSite($site);
        } catch (Throwable) {
            return;
        }

        if (! $context->isPlatform()) {
            return;
        }

        $key = self::KEY_PREFIX.$site->id;

        try {
            if ($pause) {
                $this->hostMap->putText($key, '1', $context);
            } else {
                $this->hostMap->deleteText($key, $context);
            }
        } catch (Throwable $e) {
            Log::warning('starter traffic gate failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }
    }
}
