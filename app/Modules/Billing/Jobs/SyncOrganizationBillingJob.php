<?php

namespace App\Modules\Billing\Jobs;

use App\Models\BillingSubscriptionSyncEvent;
use App\Models\Organization;
use App\Modules\Billing\Services\BillingSubscriptionSyncEventRecorder;
use App\Modules\Billing\Services\OrganizationBillingStateComputer;
use App\Modules\Billing\Services\StripeSubscriptionSyncer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reconciles an organization's Stripe subscription against its current
 * server fleet. Dispatched by lifecycle events (server created/ready/deleted,
 * tier changed) and by the nightly sweep command.
 *
 * Idempotent and uniquely-keyed per org so overlapping dispatches collapse.
 */
class SyncOrganizationBillingJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public function __construct(
        public string $organizationId,
        public string $trigger = 'manual',
    ) {}

    public function uniqueId(): string
    {
        return $this->organizationId;
    }

    public function handle(
        OrganizationBillingStateComputer $computer,
        StripeSubscriptionSyncer $syncer,
        BillingSubscriptionSyncEventRecorder $eventRecorder,
    ): void {
        $organization = Organization::find($this->organizationId);
        if (! $organization) {
            return;
        }

        // Only sync orgs on the new Standard plan. Enterprise subs are managed
        // by hand in Stripe; legacy Pro subs are flat-fee and have no quantities.
        if (! $organization->onStandardSubscription()) {
            return;
        }

        $desired = $computer->compute($organization);

        try {
            $changes = $syncer->reconcile($organization, $desired);
            $eventRecorder->record(
                organization: $organization,
                trigger: $this->trigger,
                status: $changes === []
                    ? BillingSubscriptionSyncEvent::STATUS_NO_OP
                    : BillingSubscriptionSyncEvent::STATUS_SUCCESS,
                changes: $changes,
                desiredState: $desired->toArray(),
                monthlyTotalCents: $desired->monthlyTotalCents,
            );
        } catch (Throwable $e) {
            $eventRecorder->record(
                organization: $organization,
                trigger: $this->trigger,
                status: BillingSubscriptionSyncEvent::STATUS_FAILED,
                changes: [],
                desiredState: $desired->toArray(),
                monthlyTotalCents: $desired->monthlyTotalCents,
                errorMessage: $e->getMessage(),
            );

            throw $e;
        }
    }
}
