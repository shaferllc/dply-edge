<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Jobs;
use App\Models\EdgeQueue;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function jobsPageApp(Organization $org, User $user, string $name, string $runtime, array $connections, $createdAt): Site
{
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);

    $site = Site::factory()->create([
        'organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id, 'name' => $name,
        'type' => SiteType::Static, 'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => $runtime, 'connections' => $connections]],
    ]);
    $site->forceFill(['created_at' => $createdAt])->save();

    return $site->fresh();
}

test('the jobs page says which app runs a shared queue and why workers cannot run', function () {
    Http::fake();
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    EdgeQueue::query()->create(['organization_id' => $org->id, 'name' => 'emails', 'cloudflare_id' => 'q-1', 'cloudflare_name' => 'org-emails']);
    $queue = [['kind' => 'queue', 'name' => 'JOBS', 'host' => 'jobs.internal', 'target' => 'org-emails']];

    $older = jobsPageApp($org, $user, 'api-app', 'container', $queue, now()->subDays(3));
    $newer = jobsPageApp($org, $user, 'marketing-site', 'ssr', $queue, now()->subDay());

    // The first app attached consumes; the newer one only sends to it.
    Livewire::actingAs($user)->test(Jobs::class, ['server' => $newer->server, 'site' => $newer])
        ->assertSee('Sends to api-app')
        ->assertSee('which api-app runs');

    // The consumer sees it runs the queue; it is not a Laravel app, so workers explain why not.
    Livewire::actingAs($user)->test(Jobs::class, ['server' => $older->server, 'site' => $older])
        ->assertSee('This app runs the jobs on the')
        ->assertSee('Runs here')
        ->assertSee('they need a Laravel app');
});
