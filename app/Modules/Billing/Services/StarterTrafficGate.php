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
 * A trial has not been charged yet, so container traffic past its spending
 * cap is a loss: Cloudflare keeps billing while the container is awake. Once
 * the cap is used (or the org has no plan), write a KV flag the edge worker
 * checks before it starts the container. Worker-only sites are stopped by the
 * paused page instead (OrganizationBillingEnforcer). Paid orgs are flagged
 * only past a spending cap they chose (StarterUsageBudget::paidLimitCents);
 * otherwise their usage is invoiced.
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
        // No plan (trial over, unpaid): nothing runs. On a trial, or paid with a cap: past it.
        $pause = ! $organization->hasPlan() || $this->budget->status($organization)['exhausted'];

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
