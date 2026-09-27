<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeBotProtectionPageTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\BotProtection;
use App\Livewire\Sites\EdgeSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function edgeBotProtectionSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Edge App',
        'slug' => 'edge-app',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'build' => ['command' => 'npm run build', 'output_dir' => 'dist'],
                'routing' => ['hostname' => 'edge-app.on-dply.site'],
            ],
        ],
    ]);

    return [$user, $server, $site];
}

test('edge bot protection section renders without turnstile mode saved', function () {
    [$user, $server, $site] = edgeBotProtectionSite();

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'bot-protection']))
        ->assertOk()
        ->assertSee('Bot protection', false);

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'bot-protection'])
        ->assertSee('How this works')
        ->assertSee('Enable bot protection')
        ->assertSee('Generate keys')
        ->assertSee('Site key (public)');
});

test('edge bot protection generates keys with fake edge', function () {
    [$user, $server, $site] = edgeBotProtectionSite();

    $component = Livewire::actingAs($user)
        ->test(BotProtection::class, ['server' => $server, 'site' => $site])
        ->call('generateKeys')
        ->assertSet('enabled', true);

    expect((string) $component->get('site_key'))->toStartWith('0x4AAAAAAAFakeSite')
        ->and((string) $component->get('secret_key'))->toStartWith('0x4AAAAAAAFakeSecret');

    $site->refresh();
    $turnstile = $site->edgeMeta()['turnstile'] ?? [];
    expect($turnstile['enabled'] ?? false)->toBeTrue()
        ->and($turnstile['generated'] ?? false)->toBeTrue()
        ->and((string) ($turnstile['site_key'] ?? ''))->toStartWith('0x4AAAAAAAFakeSite');
});

test('a site viewer never receives the turnstile secret key', function () {
    [$owner, $server, $site] = edgeBotProtectionSite();
    $site->mergeEdgeMeta(['turnstile' => ['enabled' => true, 'site_key' => 'pub-key', 'secret_key' => 'super-secret-turnstile']]);
    $site->save();

    $viewer = User::factory()->create();
    $site->organization->users()->attach($viewer->id, ['role' => 'member']);
    $workspace = Workspace::factory()->create(['organization_id' => $site->organization_id, 'user_id' => $owner->id]);
    $workspace->members()->create(['user_id' => $viewer->id, 'role' => WorkspaceMember::ROLE_VIEWER]);
    $site->update(['workspace_id' => $workspace->id]);
    expect($viewer->can('view', $site))->toBeTrue()->and($viewer->can('update', $site))->toBeFalse();

    Livewire::actingAs($viewer)
        ->test(BotProtection::class, ['server' => $server, 'site' => $site])
        ->assertSet('site_key', 'pub-key')
        ->assertSet('secret_key', '');

    Livewire::actingAs($owner)
        ->test(BotProtection::class, ['server' => $server, 'site' => $site])
        ->assertSet('secret_key', 'super-secret-turnstile');
});
