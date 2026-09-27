<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Environment;
use App\Models\EdgeSiteEnvVar;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\Sites\OrganizationSecretManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array{0: Site, 1: User, 2: Organization} */
function envTabSite(): array
{
    $org = Organization::factory()->create(['comped_until' => now()->addYear()]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'edge_backend' => 'dply_edge',
        'type' => SiteType::Static,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => 'static']],
    ]);
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $site->forceFill(['user_id' => $user->id])->save();
    $site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    foreach (['API_TOKEN' => 'sekrit-value', 'APP_NAME' => 'Waypost'] as $key => $value) {
        (new EdgeSiteEnvVar(['site_id' => $site->id, 'key' => $key, 'value' => $value, 'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION]))->save();
    }

    return [$site->refresh(), $user, $org];
}

test('the owner edits the env text, and saving re-renders the new keys', function () {
    [$site, $user, $org] = envTabSite();
    $secret = app(OrganizationSecretManager::class)->create($org, 'APP_NAME', 'vault', null, $user);
    app(OrganizationSecretManager::class)->link($site, $secret);

    $page = Livewire::actingAs($user)->test(Environment::class, ['server' => $site->server, 'site' => $site])
        ->assertSet('edgeEnvText', "API_TOKEN=sekrit-value\nAPP_NAME=Waypost")
        ->assertSet('pending', false)
        ->assertSeeHtml('title="Override — this secret wins over the same key in the site .env."');

    $page->set('edgeEnvText', "API_TOKEN=sekrit-value\nNEW_KEY=1")
        ->assertSet('pending', true)
        ->call('saveEdgeEnvText')
        ->assertHasNoErrors()
        ->assertSet('edgeEnvText', "API_TOKEN=sekrit-value\nNEW_KEY=1")
        ->assertSet('pending', false)
        // APP_NAME left the site env, so the linked secret no longer overrides it.
        ->assertDontSeeHtml('title="Override — this secret wins over the same key in the site .env."');

    expect($site->edgeEnvVars()->pluck('key')->all())->toBe(['API_TOKEN', 'NEW_KEY']);
});

test('a viewer sees the keys but no value reaches the page or the snapshot', function () {
    [$site, $owner, $org] = envTabSite();
    $viewer = User::factory()->create();
    $org->users()->attach($viewer->id, ['role' => 'member']);
    $workspace = Workspace::factory()->create(['organization_id' => $org->id, 'user_id' => $owner->id]);
    $workspace->members()->create(['user_id' => $viewer->id, 'role' => WorkspaceMember::ROLE_VIEWER]);
    $site->update(['workspace_id' => $workspace->id]);
    expect($viewer->can('view', $site))->toBeTrue()->and($viewer->can('update', $site))->toBeFalse();

    $page = Livewire::actingAs($viewer)->test(Environment::class, ['server' => $site->server, 'site' => $site])
        ->assertSee('API_TOKEN')
        ->assertSet('edgeEnvText', '');

    expect($page->html())->not->toContain('sekrit-value')
        ->and(json_encode($page->snapshot))->not->toContain('sekrit-value');
});

test('the link-secret modal body renders only once opened', function () {
    [$site, $user] = envTabSite();

    $page = Livewire::actingAs($user)->test(Environment::class, ['server' => $site->server, 'site' => $site])
        ->assertDontSee('Or link an existing vault secret');

    $page->call('openLinkOrganizationSecretModal')
        ->assertSee('Or link an existing vault secret');
});

test('an action round-trip stays within its query budget', function () {
    [$site, $user] = envTabSite();
    $page = Livewire::actingAs($user)->test(Environment::class, ['server' => $site->server, 'site' => $site]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $page->call('discardEdgeEnv');

    // Was 17 before env rows were memoized and the closed modal stopped querying.
    expect($queries)->toBeLessThanOrEqual(12);
});
