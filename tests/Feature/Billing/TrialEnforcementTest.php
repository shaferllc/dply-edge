<?php

declare(strict_types=1);

namespace Tests\Feature\Billing\TrialEnforcementTest;

use App\Models\EdgeDataUsage;
use App\Models\EdgeSiteEnvVar;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Listeners\CaptureTrialCardFingerprint;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\OrganizationBillingEnforcer;
use App\Modules\Billing\Services\PlanCheckout;
use App\Modules\Billing\Services\StarterUsageBudget;
use App\Modules\Edge\Livewire\Create;
use App\Notifications\OrganizationBillingNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Events\WebhookReceived;
use Livewire\Livewire;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

uses(RefreshDatabase::class);

function org(array $attributes = []): Organization
{
    $org = Organization::factory()->create($attributes);
    $org->users()->attach(User::factory()->create()->id, ['role' => 'owner']);

    return $org->fresh();
}

function edgeSite(Organization $org): Site
{
    $server = Server::factory()->create(['organization_id' => $org->id]);

    return Site::factory()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'edge_backend' => 'dply_edge']);
}

test('D1 and Queues usage draws the trial credit', function () {
    config(['subscription.standard.trial.spending_limit_cents' => 1]);
    $org = org(['trial_ends_at' => now()->addDays(3)]);
    EdgeDataUsage::query()->create(['organization_id' => $org->id, 'date' => now()->toDateString(), 'd1_rows_written' => 10_000]);

    expect(app(StarterUsageBudget::class)->status($org)['exhausted'])->toBeTrue();
});

test('the trial credit counts from the trial start, not the 1st of the month', function () {
    config(['subscription.standard.trial.spending_limit_cents' => 1]);
    $this->travelTo('2026-10-02 12:00:00');
    $org = org(['trial_ends_at' => now()->addDays(2)]); // started Sep 29
    EdgeDataUsage::query()->create(['organization_id' => $org->id, 'date' => '2026-09-30', 'd1_rows_written' => 10_000]);
    EdgeDataUsage::query()->create(['organization_id' => $org->id, 'date' => '2026-09-20', 'd1_rows_written' => 10_000]);

    expect(app(StarterUsageBudget::class)->status($org)['used_cents'])->toBe(1);
});

test('a trial over its cap is paused hourly (paused page too) and resumes once it converts', function () {
    config(['subscription.standard.trial.spending_limit_cents' => 1]);
    Notification::fake();
    $org = org(['trial_ends_at' => now()->addDays(4)]);
    edgeSite($org);
    EdgeDataUsage::query()->create(['organization_id' => $org->id, 'date' => now()->toDateString(), 'd1_rows_written' => 10_000]);

    $this->artisan('dply:billing:enforce')->assertSuccessful();
    $org = $org->fresh();
    expect($org->billing_paused_at)->not->toBeNull()
        ->and($org->billing_notices)->toHaveKey('capped')
        ->and($org->hasPlan())->toBeTrue();

    // The trial ends unpaid: now it is the ordinary pause, with its email.
    $this->travel(5)->days();
    $this->artisan('dply:billing:enforce')->assertSuccessful();
    expect($org->fresh()->billing_notices)->toHaveKey('paused');
    Notification::assertSentTimes(OrganizationBillingNotice::class, 3); // started, capped, paused
    // Hitting the cap emails the owners, not only notification channels.
    Notification::assertSentTo($org->users()->wherePivot('role', 'owner')->first(), OrganizationBillingNotice::class, fn (OrganizationBillingNotice $n) => $n->kind === 'capped'
        && str_contains(implode(' ', $n->toMail($org)->introLines), 'add a card for your plan on the billing page, then choose End trial now'));

    $org->forceFill(['comped_until' => now()->addMonth()])->save();
    $this->artisan('dply:billing:enforce')->assertSuccessful();
    expect($org->fresh()->billing_paused_at)->toBeNull()
        ->and($org->fresh()->billing_notices)->not->toHaveKey('capped');
});

test('a paid org past its own spending cap pauses, and resumes when the cap is removed', function () {
    config(['subscription.standard.stripe.tier_pro' => 'price_tier_pro', 'subscription.standard.tiers.pro.usage_credit_cents' => 1]);
    Notification::fake();
    $org = org(['spending_cap_cents' => 0]);
    Subscription::factory()->withPrice('price_tier_pro')->active()->create(['organization_id' => $org->id]);
    edgeSite($org);
    EdgeDataUsage::query()->create(['organization_id' => $org->id, 'date' => now()->toDateString(), 'd1_rows_written' => 10_000]);

    $this->artisan('dply:billing:enforce')->assertSuccessful();
    $org = $org->fresh();
    expect($org->billing_paused_at)->not->toBeNull()
        ->and($org->billing_notices)->toHaveKey('spend_capped')
        ->and($org->billing_notices)->not->toHaveKey('paused');
    Notification::assertSentTo($org->users()->wherePivot('role', 'owner')->first(), OrganizationBillingNotice::class, fn (OrganizationBillingNotice $n) => $n->kind === 'spend_capped');

    // Still paused an hour later: never treated as "no plan", so no purge clock.
    $this->artisan('dply:billing:enforce')->assertSuccessful();
    expect($org->fresh()->billing_notices)->not->toHaveKey('paused');

    $org->forceFill(['spending_cap_cents' => null])->save();
    $this->artisan('dply:billing:enforce')->assertSuccessful();
    expect($org->fresh()->billing_paused_at)->toBeNull()
        ->and($org->fresh()->billing_notices)->not->toHaveKey('spend_capped');
});

test('a paid org paused at its spending cap resumes when the next period starts', function () {
    config(['subscription.standard.stripe.tier_pro' => 'price_tier_pro', 'subscription.standard.tiers.pro.usage_credit_cents' => 1]);
    Notification::fake();
    $this->travelTo('2026-10-15 12:00:00');
    $org = org(['spending_cap_cents' => 0]);
    Subscription::factory()->withPrice('price_tier_pro')->active()->create(['organization_id' => $org->id]);
    edgeSite($org);
    EdgeDataUsage::query()->create(['organization_id' => $org->id, 'date' => now()->toDateString(), 'd1_rows_written' => 10_000]);

    $this->artisan('dply:billing:enforce')->assertSuccessful();
    expect($org->fresh()->billing_paused_at)->not->toBeNull();

    // No Stripe period on file: the calendar month, so November starts fresh.
    $this->travelTo('2026-11-01 01:00:00');
    $this->artisan('dply:billing:enforce')->assertSuccessful();
    expect($org->fresh()->billing_paused_at)->toBeNull()
        ->and($org->fresh()->billing_notices)->not->toHaveKey('spend_capped');
});

test('a paid org without a spending cap is never paused for usage', function () {
    config(['subscription.standard.stripe.tier_pro' => 'price_tier_pro', 'subscription.standard.tiers.pro.usage_credit_cents' => 1]);
    $org = org();
    Subscription::factory()->withPrice('price_tier_pro')->active()->create(['organization_id' => $org->id]);
    EdgeDataUsage::query()->create(['organization_id' => $org->id, 'date' => now()->toDateString(), 'd1_rows_written' => 10_000]);

    $this->artisan('dply:billing:enforce')->assertSuccessful();

    expect($org->fresh()->billing_paused_at)->toBeNull();
});

test('a trial under its cap is not paused', function () {
    $org = org(['trial_ends_at' => now()->addDays(4)]);

    $this->artisan('dply:billing:enforce')->assertSuccessful();

    expect($org->fresh()->billing_paused_at)->toBeNull();
});

test('pausing puts dply Valkey and databases to sleep; resuming restores their settings', function () {
    config(['edge.valkey.api_url' => 'http://gateway.test', 'edge.valkey.token' => 'tok']);
    Http::fake(['gateway.test/*' => Http::response([])]);
    $org = org(['trial_ends_at' => now()->subDay()]);
    $site = edgeSite($org);
    $site->mergeEdgeMeta([
        'connections' => [['kind' => 'redis', 'name' => 'CACHE', 'host' => 'dply.app.cache.internal', 'target' => 'valkey:app-cache', 'plan' => 'pro_5g']],
        'valkey_sleep' => ['valkey:app-cache' => 0],
        'database' => ['provider' => 'dply', 'remote_id' => 'pg-app', 'engine' => 'postgres', 'size' => '0.25', 'suspend' => -1, 'disk_gb' => 5],
    ]);
    $site->save();
    EdgeSiteEnvVar::query()->create(['site_id' => $site->id, 'key' => 'REDIS_URL', 'value' => 'redis://default:vpass@x:6379', 'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION]);
    EdgeSiteEnvVar::query()->create(['site_id' => $site->id, 'key' => 'DB_PASSWORD', 'value' => 'dpass', 'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION]);

    app(OrganizationBillingEnforcer::class)->enforce($org);

    $puts = fn (string $id) => collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/tenants/'.$id))->values();
    $slept = fn (string $id) => collect(Http::recorded())->contains(fn ($pair) => str_ends_with($pair[0]->url(), '/tenants/'.$id.'/sleep'));
    expect($slept('app-cache'))->toBeTrue()->and($slept('pg-app'))->toBeTrue()
        ->and($puts('app-cache')->last()['sleep_after'])->toBe(60)
        ->and($puts('app-cache')->last()['password'])->toBe('vpass')
        ->and($puts('pg-app')->last()['sleep_after'])->toBe(60)
        ->and($puts('pg-app')->last()['password'])->toBe('dpass');

    $org->forceFill(['comped_until' => now()->addMonth()])->save();
    app(OrganizationBillingEnforcer::class)->enforce($org->fresh());

    expect($puts('app-cache')->last()['sleep_after'])->toBe(0) // stays on again
        ->and($puts('pg-app')->last()['sleep_after'])->toBe(0);
});

test('the 3-days-left email goes once, and not to a trial that already has under a day', function () {
    Notification::fake();
    $org = org(['trial_ends_at' => now()->addDays(3)->subHour()]);

    $this->artisan('dply:billing:enforce')->assertSuccessful();
    $this->artisan('dply:billing:enforce')->assertSuccessful();

    Notification::assertSentTimes(OrganizationBillingNotice::class, 2);
    expect($org->fresh()->billing_notices)->toHaveKeys(['trial_started', 'trial_ending_soon']);

    $late = org(['trial_ends_at' => now()->addHours(12)]);
    $this->artisan('dply:billing:enforce', ['--org' => $late->id])->assertSuccessful();
    expect($late->fresh()->billing_notices)->not->toHaveKey('trial_ending_soon');
});

test('a card that already had a trial on another org gets none', function () {
    $first = org();
    $second = org();

    expect(CaptureTrialCardFingerprint::refuseTrial($first, 'fp_1', true))->toBeFalse()
        ->and($first->fresh()->trial_card_fingerprint)->toBe('fp_1')
        ->and(CaptureTrialCardFingerprint::refuseTrial($second, 'fp_1', true))->toBeTrue()
        ->and(CaptureTrialCardFingerprint::refuseTrial($second, 'fp_1', false))->toBeFalse()
        ->and(CaptureTrialCardFingerprint::refuseTrial($first, 'fp_1', true))->toBeFalse(); // a retried webhook
});

test('the subscription webhook ends a reused card’s trial at Stripe', function () {
    config(['cashier.secret' => 'sk_test_x']);
    org()->forceFill(['trial_card_fingerprint' => 'fp_1'])->save();
    $org = org();
    $org->forceFill(['stripe_id' => 'cus_2'])->save();
    $calls = [];
    $client = Mockery::mock(ClientInterface::class);
    $client->shouldReceive('request')->andReturnUsing(function ($method, $url, $headers, $params) use (&$calls) {
        $calls[] = [$method, $url, $params];

        return [json_encode(['id' => 'sub_2', 'object' => 'subscription', 'status' => 'trialing', 'default_payment_method' => ['id' => 'pm_1', 'object' => 'payment_method', 'card' => ['fingerprint' => 'fp_1']]]), 200, []];
    });
    ApiRequestor::setHttpClient($client);

    try {
        (new CaptureTrialCardFingerprint)->handle(new WebhookReceived(['type' => 'customer.subscription.created', 'data' => ['object' => ['id' => 'sub_2', 'customer' => 'cus_2']]]));
    } finally {
        ApiRequestor::setHttpClient(null);
    }

    expect($calls)->toHaveCount(2)
        ->and($calls[1][0])->toBe('post')
        ->and($calls[1][2]['trial_end'])->toBe('now');
});

test('deploy without a plan goes to Checkout and comes back to the draft', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create(['trial_ends_at' => null]);
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    $checkout = Mockery::mock(PlanCheckout::class);
    $checkout->shouldReceive('url')->once()->withArgs(function ($o, $tier, $success) {
        return $tier === 'pro' && str_contains($success, 'repo=acme%2Fsite') && str_contains($success, 'checkout=success');
    })->andReturn('https://checkout.stripe.test/c/1');
    app()->instance(PlanCheckout::class, $checkout);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'Site')
        ->set('repo', 'acme/site')
        ->set('branch', 'main')
        ->call('deploy')
        ->assertRedirect('https://checkout.stripe.test/c/1');

    expect(Site::query()->count())->toBe(0);
});
