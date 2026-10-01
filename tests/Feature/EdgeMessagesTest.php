<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeMessagesTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeMessageAccount;
use App\Models\EdgeMessageToken;
use App\Models\EdgeMessageUsage;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Services\EdgeMessagesCost;
use App\Modules\Edge\Livewire\Messages;
use App\Modules\Edge\Services\Messages\EdgeMessages;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'edge.messages.url' => 'https://messages.test',
        'edge.messages.operator_token' => 'op-token',
        'dply.edge.usage_billing.margin_percent' => 30,
    ]);
    // Usage answers come from $this->usage, in order; everything else is OK.
    $this->usage = [];
    Http::fake(['messages.test/*' => fn (Request $r) => str_ends_with($r->url(), '/usage')
        ? Http::response(array_shift($this->usage) ?? ['published' => 0])
        : Http::response(['ok' => true])]);
    $this->user = User::factory()->create();
    $this->org = Organization::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'owner']);
    session(['current_organization_id' => $this->org->id]);
});

it('is hidden without the flag', function () {
    Feature::for($this->org)->deactivate(EdgeContainerConnections::flag('messages'));
    $this->actingAs($this->user)->get(route('edge.messages'))->assertNotFound();
});

it('turns on with new signing keys, makes a hashed token shown once, and rotates keys', function () {
    $page = Livewire::actingAs($this->user)->test(Messages::class)->call('enable');

    $account = EdgeMessageAccount::query()->where('organization_id', $this->org->id)->firstOrFail();
    expect($account->current_signing_key)->toStartWith('sig_')->and($account->next_signing_key)->not->toBe($account->current_signing_key);
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === "https://messages.test/_operator/orgs/{$this->org->id}"
        && $r->hasHeader('Authorization', 'Bearer op-token') && $r['enabled'] === true && $r['currentSigningKey'] === $account->current_signing_key);

    $made = $page->set('label', 'Production app')->instance()->createToken(app(EdgeMessages::class));
    expect($made['secret'])->toStartWith('dmq_');
    $token = EdgeMessageToken::query()->firstOrFail();
    expect($token->token_hash)->toBe(hash('sha256', $made['secret']))->and($token->last4)->toBe(substr($made['secret'], -4));
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://messages.test/_operator/tokens/'.$token->token_hash && $r['org'] === $this->org->id);

    $next = $account->next_signing_key;
    $page->call('rotateKeys');
    expect($account->fresh()->current_signing_key)->toBe($next);
    expect(Livewire::actingAs($this->user)->test(Messages::class)->instance()->signingKeys()['current'])->toBe($next);

    $page->call('revokeToken', $token->id);
    expect(EdgeMessageToken::query()->count())->toBe(0);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://messages.test/_operator/tokens/'.$token->token_hash);
});

it('collects published messages as a delta and bills them per 100,000 under data', function () {
    app(EdgeMessages::class)->enable($this->org);
    $this->usage = [['published' => 400_000], ['published' => 1_000_000]];

    app(EdgeMessages::class)->collectUsage();
    app(EdgeMessages::class)->collectUsage();

    expect((int) EdgeMessageUsage::query()->where('organization_id', $this->org->id)->sum('messages'))->toBe(1_000_000);
    // $0.50 per 100K is a fixed customer price: the margin (30%) is not added. 1M = $5.00.
    expect(app(EdgeMessagesCost::class)->forOrganization($this->org, now()->startOfMonth(), now()->endOfMonth()))
        ->toBe(['messages' => 1_000_000, 'cents' => 500]);
});

it('pauses with billing and deletes everything with the organization', function () {
    $messages = app(EdgeMessages::class);
    $messages->enable($this->org);
    $messages->createToken($this->org, 'ci', $this->user);

    $messages->setOrganizationEnabled($this->org, false);
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), "/_operator/orgs/{$this->org->id}") && $r['enabled'] === false);

    $messages->destroy($this->org);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), "/_operator/orgs/{$this->org->id}"));
    expect(EdgeMessageAccount::query()->count())->toBe(0)->and(EdgeMessageToken::query()->count())->toBe(0);
});

it('attaches per app: its own token, MESSAGES_* on deploy, revoked on detach', function () {
    $server = Server::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $this->org->id, 'server_id' => $server->id, 'user_id' => $this->user->id, 'type' => SiteType::Static,
        'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => 'container']],
    ]);

    $page = Livewire::actingAs($this->user)->test(Resources::class, ['server' => $server, 'site' => $site])->call('chooseConnectionKind', 'messages');

    $token = EdgeMessageToken::query()->where('site_id', $site->id)->firstOrFail();
    expect(EdgeMessageAccount::query()->where('organization_id', $this->org->id)->exists())->toBeTrue()
        ->and($token->secret)->toStartWith('dmq_')
        ->and($token->token_hash)->toBe(hash('sha256', $token->secret));
    $env = EdgeContainerConnections::messagesEnv($site->fresh());
    expect($env['MESSAGES_URL'])->toBe('https://messages.test')
        ->and($env['MESSAGES_TOKEN'])->toBe($token->secret)
        ->and($env['MESSAGES_SIGNING_KEY'])->toStartWith('sig_');

    // Asleep: no env; detached: token revoked on the Worker.
    $host = EdgeContainerConnections::resourceHost($site, 'messages');
    $page->call('sleepConnection', $host, true);
    expect(EdgeContainerConnections::messagesEnv($site->fresh()))->toBe([]);
    $page->call('removeConnection', $host);
    expect(EdgeMessageToken::query()->where('site_id', $site->id)->exists())->toBeFalse();
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://messages.test/_operator/tokens/'.$token->token_hash);
});
