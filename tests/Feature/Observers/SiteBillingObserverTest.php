<?php

namespace Tests\Feature\Observers\SiteBillingObserverTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Jobs\SyncOrganizationBillingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 | Replaces ServerObserverBillingSyncTest. Server lifecycle no longer moves the
 | bill — billing counts live Edge sites — so the sync is driven by
 | App\Modules\Billing\Observers\SiteBillingObserver.
 */
function edgeSiteFor(Organization $org): Site
{
    $user = User::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_READY,
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
    ]);
}

test('dispatches a billing sync when a site becomes edge-active', function () {
    $org = Organization::factory()->create();
    $site = edgeSiteFor($org);
    $site->update(['status' => Site::STATUS_PENDING]);

    $site->update(['status' => Site::STATUS_EDGE_ACTIVE]);

    Queue::assertPushed(SyncOrganizationBillingJob::class);
});

test('dispatches a billing sync when an edge site is deleted', function () {
    $org = Organization::factory()->create();
    $site = edgeSiteFor($org);

    $site->delete();

    Queue::assertPushed(SyncOrganizationBillingJob::class);
});
