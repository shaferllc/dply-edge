<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\Organization;
use App\Models\OrganizationBillingSnapshot;
use Carbon\CarbonInterface;

final class OrganizationBillingSnapshotWriter
{
    public function __construct(
        private readonly OrganizationBillingStateComputer $billingStateComputer,
    ) {}

    public function writeForOrganization(Organization $organization, ?CarbonInterface $snapshotDate = null): OrganizationBillingSnapshot
    {
        $state = $this->billingStateComputer->compute($organization);
        $date = ($snapshotDate ?? now())->toDateString();

        return OrganizationBillingSnapshot::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'snapshot_date' => $date,
            ],
            [
                'monthly_total_cents' => $state->monthlyTotalCents,
                'category_breakdown' => $this->categoryBreakdown($state),
                'fleet_counts' => [
                    'edge' => $state->edgeCount,
                ],
                'edge_usage_cents' => $state->edgeUsageSubtotalCents,
                'subscription_interval' => $this->subscriptionInterval($organization),
            ],
        );
    }

    /**
     * @return array<string, int>
     */
    private function categoryBreakdown(DesiredBillingState $state): array
    {
        return [
            'plan_cents' => $state->planPriceCents,
            'edge_cents' => $state->edgeSubtotalCents,
            'edge_usage_cents' => $state->edgeUsageSubtotalCents,
        ];
    }

    private function subscriptionInterval(Organization $organization): ?string
    {
        $subscription = $organization->subscription('default');
        if ($subscription === null || ! $subscription->valid()) {
            return null;
        }

        return SubscriptionPlanResolver::isYearly($subscription) ? 'year' : 'month';
    }
}
