<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\EdgeRealtimeApp;
use App\Models\Organization;
use App\Models\User;
use App\Modules\Edge\Services\Realtime\EdgeRealtimeApps;
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
    public function handle(Organization $organization, User $actor, bool $requireAnotherOrganization = true): void
    {
        $this->guard($organization, $actor, $requireAnotherOrganization);

        // Realtime apps cascade with the org, but their relay KV records would
        // stay live: remove them first, outside the transaction (remote calls).
        foreach (EdgeRealtimeApp::query()->where('organization_id', $organization->id)->get() as $app) {
            try {
                app(EdgeRealtimeApps::class)->destroy($app);
            } catch (\Throwable $e) {
                report($e);

                throw ValidationException::withMessages([
                    'delete_confirm' => __('A Realtime app could not be removed. Try again in a moment.'),
                ]);
            }
        }

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
     * Account deletion passes $requireAnotherOrganization = false: the owner is
     * leaving dply, so there is nowhere they need to land.
     *
     * @throws ValidationException
     */
    public function guard(Organization $organization, User $actor, bool $requireAnotherOrganization = true): void
    {
        if ($organization->servers()->exists() || $organization->sites()->exists()) {
            throw ValidationException::withMessages([
                'delete_confirm' => __('Delete every app in this organization before deleting it.'),
            ]);
        }

        if ($organization->onAnyPaidPlan()) {
            throw ValidationException::withMessages([
                'delete_confirm' => __('Cancel this organization\'s subscription before deleting it.'),
            ]);
        }

        if (! $requireAnotherOrganization) {
            return;
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
