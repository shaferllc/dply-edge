<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\BillingSubscriptionSyncEvent;
use App\Models\Organization;

final class BillingSubscriptionSyncEventRecorder
{
    /**
     * @param  array<int, array<string, mixed>>  $changes
     * @param  array<string, mixed>  $desiredState
     */
    public function record(
        Organization $organization,
        string $trigger,
        string $status,
        array $changes,
        array $desiredState,
        int $monthlyTotalCents,
        ?string $errorMessage = null,
    ): BillingSubscriptionSyncEvent {
        return BillingSubscriptionSyncEvent::query()->create([
            'organization_id' => $organization->id,
            'trigger' => $trigger,
            'status' => $status,
            'changes' => $changes,
            'desired_state' => $desiredState,
            'monthly_total_cents' => max(0, $monthlyTotalCents),
            'error_message' => $errorMessage,
        ]);
    }
}
