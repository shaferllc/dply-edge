<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeQueuesTest;

use App\Models\EdgeQueue;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Livewire\Queues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok']);
    $this->user = User::factory()->create();
    $this->org = Organization::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'owner']);
    session(['current_organization_id' => $this->org->id]);
});

test('creating a queue records its cloudflare id and per-org name', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acct/queues' => Http::response(['success' => true, 'result' => ['queue_id' => 'q-1', 'queue_name' => 'x']]),
        'api.cloudflare.com/client/v4/graphql' => Http::response(['data' => ['viewer' => ['accounts' => [['queueBacklogAdaptiveGroups' => [['dimensions' => ['queueId' => 'q-1'], 'avg' => ['messages' => 42]]]]]]]]),
    ]);

    Livewire::actingAs($this->user)->test(Queues::class)
        ->set('name', 'emails')
        ->call('create')
        ->assertHasNoErrors()
        ->assertSee('emails')
        ->assertSee('42');

    expect(EdgeQueue::query()->first())
        ->cloudflare_id->toBe('q-1')
        ->cloudflare_name->toBe('dply-'.strtolower((string) $this->org->id).'-emails');
});

test('attaching binds the queue name to the project and shows it', function () {
    $queue = EdgeQueue::query()->create(['organization_id' => $this->org->id, 'name' => 'emails', 'cloudflare_id' => 'q-1', 'cloudflare_name' => 'dply-x-emails']);
    $server = Server::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->user->id]);
    $site = Site::factory()->create(['organization_id' => $this->org->id, 'server_id' => $server->id, 'user_id' => $this->user->id, 'edge_backend' => 'dply_edge', 'name' => 'Shop']);
    Http::fake(['*' => Http::response(['data' => []])]);

    Livewire::actingAs($this->user)->test(Queues::class)
        ->set('attachQueue', $queue->id)
        ->set('attachSite', $site->id)
        ->call('attach')
        ->assertHasNoErrors()
        ->assertSee('Shop (JOBS)');

    expect($site->fresh()->edgeMeta()['bindings_overrides'])->toBe([['name' => 'JOBS', 'kind' => 'queue', 'value' => 'dply-x-emails']]);
});

test('send test and delete call the queues api', function () {
    $queue = EdgeQueue::query()->create(['organization_id' => $this->org->id, 'name' => 'emails', 'cloudflare_id' => 'q-1', 'cloudflare_name' => 'dply-x-emails']);
    Http::fake(['*' => Http::response(['success' => true, 'result' => [], 'data' => []])]);

    Livewire::actingAs($this->user)->test(Queues::class)
        ->call('sendTest', $queue->id)
        ->call('delete', $queue->id);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/queues/q-1/messages'));
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/queues/q-1'));
    expect(EdgeQueue::query()->count())->toBe(0);
});
