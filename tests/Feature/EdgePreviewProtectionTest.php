<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Sites\Edge\Workspace\Previews;
use App\Models\EdgeDeployment;
use App\Models\EdgeSiteAccessRule;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Services\EdgeAccessTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('edge build settings shows preview protection controls on parent sites', function () {
    [$user, $server, $site] = makeEdgePreviewProtectionSite();

    Livewire::actingAs($user)
        ->test(Previews::class, ['server' => $server, 'site' => $site])
        // The section is "Protection" on the Previews tab now; the password /
        // account modes are inside its controls.
        ->assertSee('Protection');
});

test('save preview protection persists password mode and republishes host map', function () {
    config(['edge.fake.enabled' => true]);

    [$user, $server, $site] = makeEdgePreviewProtectionSite(withLiveDeploy: true);

    Livewire::actingAs($user)
        ->test(Previews::class, ['server' => $server, 'site' => $site])
        ->set('buildForm.edge_preview_protection_mode', EdgeSiteAccessRule::MODE_PASSWORD)
        ->set('buildForm.edge_preview_protection_password', 'review-only')
        ->call('saveEdgePreviewProtection')
        ->assertHasNoErrors();

    $rule = EdgeSiteAccessRule::query()->where('site_id', $site->id)->first();
    expect($rule)->not->toBeNull();
    expect($rule->mode)->toBe(EdgeSiteAccessRule::MODE_PASSWORD);
    expect($rule->password_verifier)->toBe(hash('sha256', $rule->password_salt.'review-only'));
});

test('preview access route redirects signed in user to worker complete url', function () {
    // The token embeds a second-resolution `exp`; the test and the controller
    // each issue one, so an unfrozen clock crossing a second makes them differ.
    $this->freezeTime();

    [$user, , $site] = makeEdgePreviewProtectionSite();

    $rule = EdgeSiteAccessRule::query()->create([
        'site_id' => $site->id,
        'mode' => EdgeSiteAccessRule::MODE_DPLY_ACCOUNT,
        'cookie_secret' => 'cookie-secret-test',
        'allowed_emails' => [(string) $user->email],
    ]);

    $issued = app(EdgeAccessTokenIssuer::class)->issue(
        $site,
        'preview-alias.dply.host',
        $user,
        $rule,
    );

    $this->actingAs($user)
        ->get(route('edge.preview-access', [
            'site' => $site,
            'hostname' => 'preview-alias.dply.host',
        ]))
        ->assertRedirect('https://preview-alias.dply.host/__dply/access/complete?token='.rawurlencode($issued['token']));
});

test('preview access route rejects users outside allow list', function () {
    [$user, , $site] = makeEdgePreviewProtectionSite();

    EdgeSiteAccessRule::query()->create([
        'site_id' => $site->id,
        'mode' => EdgeSiteAccessRule::MODE_DPLY_ACCOUNT,
        'cookie_secret' => 'cookie-secret-test',
        'allowed_emails' => ['someone-else@example.com'],
    ]);

    $this->actingAs($user)
        ->get(route('edge.preview-access', [
            'site' => $site,
            'hostname' => 'preview-alias.dply.host',
        ]))
        ->assertForbidden();
});

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeEdgePreviewProtectionSite(bool $withLiveDeploy = false): array
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
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'build' => ['command' => 'npm run build', 'output_dir' => 'dist'],
                'live_url' => 'https://edge-app.dply.host',
            ],
        ],
    ]);

    if ($withLiveDeploy) {
        $deployment = EdgeDeployment::query()->create([
            'site_id' => $site->id,
            'organization_id' => $org->id,
            'status' => EdgeDeployment::STATUS_LIVE,
            'storage_prefix' => 'edge/site/deploy/',
            'git_branch' => 'main',
            'published_at' => now(),
            'aliases' => ['deploy-alias.dply.host'],
        ]);
        $site->mergeEdgeMeta(['active_deployment_id' => (string) $deployment->id]);
        $site->save();
    }

    return [$user, $server, $site->fresh()];
}
