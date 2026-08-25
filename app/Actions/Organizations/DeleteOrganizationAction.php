<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owner-only organization deletion for the General settings danger zone.
 *
 * Conservative by design: an org can only be deleted when it owns no real
 * infrastructure (no servers, no sites), carries no active paid subscription,
 * and is not the actor's last organization. This avoids orphaning live
 * infrastructure or a Stripe subscription, and guarantees the user still has
 * somewhere to land. Teardown of the org's config rows runs in a transaction;
 * a stray FK constraint rolls the whole thing back and surfaces as a friendly
 * "remove related resources first" message rather than a 500.
 */
class DeleteOrganizationAction
{
    /**
     * @throws ValidationException
     */
    public function handle(Organization $organization, User $actor): void
    {
        $this->guard($organization, $actor);

        DB::transaction(function () use ($organization): void {
            // Detach memberships first (pivot has no org cascade).
            $organization->users()->detach();

            // Delete org-owned config rows. Many cascade at the DB level — this
            // is belt-and-braces for the relations that don't, so the final
            // org delete can't trip a RESTRICT foreign key.
            foreach ([
                'invitations', 'apiTokens', 'notificationWebhookDestinations',
                'notificationChannels', 'providerCredentials', 'statusPages',
                'billingSnapshots', 'billingSubscriptionSyncEvents', 'teams',
                'projects', 'workspaces', 'auditLogs',
            ] as $relation) {
                $organization->{$relation}()->delete();
            }

            $organization->delete();
        });
    }

    /**
     * @throws ValidationException
     */
    private function guard(Organization $organization, User $actor): void
    {
        if ($organization->servers()->exists() || $organization->sites()->exists()) {
            throw ValidationException::withMessages([
                'delete_confirm' => __('Remove all servers and sites from this organization before deleting it.'),
            ]);
        }

        if ($organization->onAnyPaidPlan()) {
            throw ValidationException::withMessages([
                'delete_confirm' => __('Cancel this organization\'s subscription before deleting it.'),
            ]);
        }

        $otherOrgs = $actor->organizations()
            ->where('organizations.id', '!=', $organization->id)
            ->exists();

        if (! $otherOrgs) {
            throw ValidationException::withMessages([
                'delete_confirm' => __('This is your only organization — create another before deleting this one.'),
            ]);
        }
    }
}
