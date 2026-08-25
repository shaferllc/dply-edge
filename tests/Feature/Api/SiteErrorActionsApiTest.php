<?php

namespace Tests\Feature\Api\SiteErrorActionsApiTest;

use App\Models\ApiToken;
use App\Models\ErrorEvent;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{0: Site, 1: string}
 */
function siteWithToken(array $abilities = ['sites.read', 'sites.write', 'commands.run']): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);

    ['plaintext' => $plaintext] = ApiToken::createToken($user, $organization, 'test', null, $abilities);

    return [$site, $plaintext];
}

function makeError(Site $site, array $attributes = []): ErrorEvent
{
    return ErrorEvent::create(array_merge([
        'organization_id' => $site->organization_id,
        'server_id' => $site->server_id,
        'site_id' => $site->id,
        'source_type' => $site->getMorphClass(),
        // (source_type, source_id) is unique — one event per originating row.
        'source_id' => (string) Str::ulid(),
        'category' => 'deploy',
        'title' => 'Deploy failed',
        'occurred_at' => now(),
    ], $attributes));
}

it('dismisses a single error and reports it gone', function () {
    [$site, $token] = siteWithToken();
    $event = makeError($site);

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/errors/dismiss", ['id' => $event->id])
        ->assertOk()
        ->assertJsonPath('data.dismissed', 1);

    expect($event->fresh()->dismissed_at)->not->toBeNull();

    // Second call finds nothing open with that id.
    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/errors/dismiss", ['id' => $event->id])
        ->assertNotFound();
});

it('dismisses every open error with all=1', function () {
    [$site, $token] = siteWithToken();
    makeError($site);
    makeError($site, ['category' => 'ssl', 'title' => 'Certificate expired']);

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/errors/dismiss", ['all' => true])
        ->assertOk()
        ->assertJsonPath('data.dismissed', 2);

    expect(ErrorEvent::whereNull('dismissed_at')->count())->toBe(0);
});

it('never touches an error on another organization\'s site', function () {
    [$site, $token] = siteWithToken();
    [$otherSite] = siteWithToken();
    $foreign = makeError($otherSite);

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/errors/dismiss", ['id' => $foreign->id])
        ->assertNotFound();

    expect($foreign->fresh()->dismissed_at)->toBeNull();
});

it('refuses to retry an error whose category is not retryable', function () {
    [$site, $token] = siteWithToken();
    $event = makeError($site);

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/errors/{$event->id}/retry")
        ->assertStatus(422);
});

it('needs sites.write to dismiss and commands.run to retry', function () {
    [$site, $readOnly] = siteWithToken(['sites.read']);
    $event = makeError($site);

    $this->withToken($readOnly)
        ->postJson("/api/v1/sites/{$site->slug}/errors/dismiss", ['id' => $event->id])
        ->assertForbidden();

    $this->withToken($readOnly)
        ->postJson("/api/v1/sites/{$site->slug}/errors/{$event->id}/retry")
        ->assertForbidden();
});

it('marks retryable events in the list payload', function () {
    [$site, $token] = siteWithToken();
    makeError($site, ['category' => 'db_engine_install']);
    makeError($site, ['category' => 'deploy']);

    $response = $this->withToken($token)->getJson("/api/v1/sites/{$site->slug}/errors");

    $response->assertOk();
    $byCategory = collect($response->json('data'))->keyBy('category');

    expect($byCategory['db_engine_install']['retryable'])->toBeTrue()
        ->and($byCategory['deploy']['retryable'])->toBeFalse();
});

it('tells clients what kind of site each row is', function () {
    [$site, $token] = siteWithToken();

    $this->withToken($token)
        ->getJson('/api/v1/sites')
        ->assertOk()
        ->assertJsonPath('data.0.kind', 'vm');

    $this->withToken($token)
        ->getJson("/api/v1/sites/{$site->slug}")
        ->assertOk()
        ->assertJsonPath('data.kind', 'vm');
});
