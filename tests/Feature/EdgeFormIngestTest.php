<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeFormIngestTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Forms;
use App\Models\EdgeFormSubmission;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Http\Controllers\EdgeFormIngestController;
use App\Modules\Edge\Support\EdgeHostMapAddons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function formsSite(): Site
{
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    return Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['forms' => [
            'enabled' => true,
            'endpoints' => [['path' => '/contact', 'to_email' => 'inbox@example.com', 'honeypot' => 'company', 'require_turnstile' => false]],
        ]]],
    ]);
}

/** @param  array<string, mixed>  $payload */
function postForm(Site $site, array $payload, ?string $key = null)
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

    return test()->call('POST', route('hooks.edge.forms', $site), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_DPLY_EDGE_FORM_SIGNATURE' => hash_hmac('sha256', $body, $key ?? EdgeFormIngestController::keyFor($site)),
    ], $body);
}

test('host map hands the worker the per-app ingest url and key', function () {
    $site = formsSite();
    $forms = EdgeHostMapAddons::payload($site)['forms'];

    expect($forms['ingest_key'])->toBe(EdgeFormIngestController::keyFor($site))
        ->and($forms['ingest_url'])->toEndWith('/hooks/edge/'.$site->id.'/forms')
        ->and(EdgeFormIngestController::keyFor($site))->not->toBe(EdgeFormIngestController::keyFor(formsSite()));
});

test('a signed submission is stored and mailed to the configured inbox', function () {
    Queue::fake();
    $site = formsSite();

    postForm($site, [
        'path' => '/contact',
        'fields' => ['name' => 'Ada', 'message' => 'Hi'],
        'submitted_at' => now()->toIso8601String(),
    ])->assertOk()->assertJson(['ok' => true]);

    $row = EdgeFormSubmission::query()->where('site_id', $site->id)->sole();
    expect($row->path)->toBe('/contact')
        ->and($row->fields)->toBe(['name' => 'Ada', 'message' => 'Hi']);
    Queue::assertPushed(CallQueuedClosure::class);
});

test('bad signatures, another app\'s key, unknown paths and stale bodies are refused', function () {
    Queue::fake();
    $site = formsSite();
    $good = ['path' => '/contact', 'fields' => ['name' => 'x'], 'submitted_at' => now()->toIso8601String()];

    postForm($site, $good, 'wrong')->assertStatus(401);
    postForm($site, $good, EdgeFormIngestController::keyFor(formsSite()))->assertStatus(401);
    postForm($site, [...$good, 'path' => '/elsewhere'])->assertStatus(404);
    postForm($site, [...$good, 'submitted_at' => now()->subHour()->toIso8601String()])->assertStatus(422);

    expect(EdgeFormSubmission::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('the forms page lists recent submissions', function () {
    $site = formsSite();
    $user = User::factory()->create();
    $site->organization->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $site->organization_id]);
    EdgeFormSubmission::query()->create(['site_id' => $site->id, 'path' => '/contact', 'fields' => ['message' => 'Hello from Ada']]);

    Livewire::actingAs($user)
        ->test(Forms::class, ['server' => $site->server, 'site' => $site])
        ->assertSee('Recent submissions')
        ->assertSee('Hello from Ada');
});
