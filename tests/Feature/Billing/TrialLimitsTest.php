<?php

declare(strict_types=1);

namespace Tests\Feature\Billing\TrialLimitsTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeContainerUsage;
use App\Models\EdgeDataUsage;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Listeners\CaptureTrialCardFingerprint;
use App\Modules\Billing\Services\StarterUsageBudget;
use App\Modules\Billing\Services\TrialRunningCost;
use App\Modules\Billing\Support\UsagePrice;
use App\Modules\Edge\Support\EdgeBuildMinutes;
use App\Modules\Edge\Support\EdgeBuildSlots;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeQueueWorkers;
use App\Modules\Edge\Support\EdgeTrialLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Laravel\Cashier\Events\WebhookReceived;
use Livewire\Livewire;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Server, 2: Site} */
function app_site(array $orgAttributes = [], string $runtime = 'container', array $edge = []): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create($orgAttributes); // on its trial by default
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id, 'type' => SiteType::Static,
        'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => $runtime] + $edge],
    ]);

    return [$user, $server, $site->fresh()];
}

function live(Site $site): void
{
    EdgeDeployment::query()->create(['site_id' => $site->id, 'organization_id' => $site->organization_id, 'status' => EdgeDeployment::STATUS_LIVE]);
}

const BIG_APP = ['container' => [
    'instance_type' => 'standard-3', 'max_instances' => 5, 'min_instances' => 2, 'sleep_after' => '6h',
    'dedicated_jobs' => true, 'jobs_always_on' => true,
    'schedules' => [['days' => 'daily', 'start' => '09:00', 'end' => '17:00', 'timezone' => 'UTC', 'min' => 3, 'max' => 8]],
    'workers' => ['enabled' => true, 'instances' => 4, 'autoscale' => true, 'max_instances' => 8, 'groups' => [['key' => 'mail', 'queues' => 'mail', 'instances' => 2]]],
]];

test('a trial app runs the smallest size, one instance, and sleeps after 5 minutes', function () {
    [, , $site] = app_site(edge: BIG_APP);

    $settings = EdgeContainerSettings::for($site);

    expect($settings['instance_type'])->toBe(EdgeTrialLimits::containerType())
        ->and($settings['max_instances'])->toBe(1)
        ->and($settings['min_instances'])->toBe(0)
        ->and($settings['sleep_after'])->toBe('5m')
        ->and($settings['dedicated_jobs'])->toBeFalse()
        ->and($settings['jobs_always_on'])->toBeFalse()
        ->and($settings['schedules'][0])->toMatchArray(['min' => 0, 'max' => 1])
        ->and(EdgeContainerSettings::peakInstances($settings))->toBe(1);
    // Queue workers: one, no autoscale, no extra groups.
    expect(EdgeQueueWorkers::allowance($site))->toMatchArray(['instances' => 1, 'autoscale' => false, 'groups' => 0])
        ->and(EdgeQueueWorkers::groups($site))->toHaveCount(1)
        ->and($settings['worker_instances'])->toBeLessThanOrEqual(1);
    // One build at a time.
    expect(EdgeBuildSlots::count($site->organization))->toBe(1);
    // The stored choice is kept for after the trial.
    expect($site->edgeMeta()['container']['instance_type'])->toBe('standard-3');
});

test('a paid app keeps its settings, and a trial app below the smallest rung keeps its size', function () {
    [, , $paid] = app_site(['comped_until' => now()->addYear()], edge: BIG_APP);
    expect(EdgeContainerSettings::for($paid))->toMatchArray(['instance_type' => 'standard-3', 'max_instances' => 5, 'sleep_after' => '6h']);

    [, , $lite] = app_site(edge: ['container' => ['instance_type' => 'lite']]);
    [, , $custom] = app_site(edge: ['container' => ['instance_type' => 'custom', 'custom_vcpu' => 4, 'custom_memory_gib' => 12, 'custom_disk_gb' => 20]]);
    [, , $rung] = app_site(edge: ['container' => ['instance_type' => 'custom-2']]);
    expect(EdgeContainerSettings::for($rung)['instance_type'])->toBe('basic');
    expect(EdgeContainerSettings::for($lite)['instance_type'])->toBe('lite')
        ->and(EdgeContainerSettings::for($custom)['instance_type'])->toBe(EdgeTrialLimits::containerType())
        ->and(EdgeContainerSettings::shape($custom)['custom'])->toBeFalse();
});

test('a trial database and Valkey run the smallest size and never stay on', function () {
    [, , $trial] = app_site();
    [, , $paid] = app_site(['comped_until' => now()->addYear()]);

    expect(EdgeTrialLimits::database($trial, '0.5', -1))->toBe([EdgeTrialLimits::databaseSize(), 300])
        ->and(EdgeTrialLimits::database($trial, '0.5', 900))->toBe([EdgeTrialLimits::databaseSize(), 900])
        ->and(EdgeTrialLimits::database($paid, '0.5', -1))->toBe(['0.5', -1])
        ->and(EdgeTrialLimits::valkey($trial, 'pro_5g', 0))->toBe([EdgeTrialLimits::valkeyClass(), 300])
        ->and(EdgeTrialLimits::valkey($paid, 'pro_5g', 0))->toBe(['pro_5g', 0]);
});

test('the resources page shows capped choices as after the trial and snaps a capped pick back', function () {
    [$user, $server, $site] = app_site();

    $page = Livewire::actingAs($user)->test(Resources::class, ['server' => $server, 'site' => $site])
        ->openSheet('resources-app')
        ->assertSee('Available after your trial')
        ->call('selectSize', 'standard-2')
        ->assertSet('draftInstanceType', EdgeTrialLimits::containerType())
        ->call('selectInstances', 3)
        ->assertSet('draftMaxInstances', 1)
        ->set('sleepAfter', '1h')
        ->assertSet('sleepAfter', '5m');

    expect($page->get('pending'))->toBeFalse();
});

test('the running estimate prices builds in flight and apps awake since the last collection', function () {
    config(['dply.edge.usage_billing.margin_percent' => 30, 'dply.edge.usage_billing.build_millicents_per_minute' => 500]);
    [, , $site] = app_site(runtime: 'static');
    EdgeDeployment::query()->create(['site_id' => $site->id, 'organization_id' => $site->organization_id, 'status' => EdgeDeployment::STATUS_BUILDING, 'build_started_at' => now()->subMinutes(10)]);

    expect(app(TrialRunningCost::class)->cents($site->organization))
        ->toBe((int) ceil(UsagePrice::customer(EdgeBuildMinutes::costMillicents(600)) / 1000));

    [, , $app] = app_site();
    live($app);
    [, , $draft] = app_site(); // never went live: not running
    expect(app(TrialRunningCost::class)->cents($draft->organization))->toBe(0);
    $shape = EdgeContainerSettings::shape($app);
    $perSecond = UsagePrice::containerPerSecond($shape['vcpu'], $shape['memory_gib'], $shape['disk_gb']);
    // Never collected: at most the lookback.
    expect(app(TrialRunningCost::class)->cents($app->organization))->toBe((int) ceil($perSecond * TrialRunningCost::LOOKBACK / 1000));

    // Collected 10 minutes ago: 10 minutes.
    $this->travelTo(now()->subMinutes(10));
    EdgeContainerUsage::query()->create(['organization_id' => $app->organization_id, 'site_id' => $app->id, 'date' => now()->toDateString(), 'cpu_seconds' => 1]);
    $this->travelBack();
    expect(app(TrialRunningCost::class)->cents($app->organization))->toBe((int) ceil($perSecond * 600 / 1000));
});

test('the estimate counts toward the trial cap, and a trial paused at its cap stays capped until it converts', function () {
    config(['subscription.standard.trial.spending_limit_cents' => 1]);
    [, , $app] = app_site();
    live($app);
    $org = $app->organization;
    $budget = app(StarterUsageBudget::class);

    expect($budget->status($org)['exhausted'])->toBeTrue(); // an awake app, nothing collected yet

    // Paused (before the capped notice is even recorded): nothing runs, so no estimate, but still capped.
    $org->forceFill(['billing_paused_at' => now()])->save();
    expect($budget->status($org->fresh()))->toMatchArray(['used_cents' => 0, 'exhausted' => true]);

    $org->forceFill(['comped_until' => now()->addYear()])->save();
    expect($budget->status($org->fresh())['exhausted'])->toBeFalse();
});

test('the 5-minute run pauses a running trial at its cap and stops its builds; idle trials wait for the hourly run', function () {
    config(['subscription.standard.trial.spending_limit_cents' => 1]);
    Notification::fake();
    Process::fake();
    [, , $building] = app_site(runtime: 'static');
    $deployment = EdgeDeployment::query()->create(['site_id' => $building->id, 'organization_id' => $building->organization_id, 'status' => EdgeDeployment::STATUS_BUILDING, 'build_started_at' => now()->subMinutes(30)]);
    [, , $idle] = app_site(runtime: 'static');
    EdgeDataUsage::query()->create(['organization_id' => $idle->organization_id, 'date' => now()->toDateString(), 'd1_rows_written' => 10_000]);

    $this->artisan('dply:billing:enforce', ['--trialing' => true])->assertSuccessful();

    expect($building->organization->fresh()->billing_paused_at)->not->toBeNull()
        ->and($deployment->fresh()->status)->toBe(EdgeDeployment::STATUS_FAILED)
        ->and($deployment->fresh()->meta['cancelled'] ?? false)->toBeTrue()
        ->and($idle->organization->fresh()->billing_paused_at)->toBeNull();
    Process::assertRan(fn ($process) => in_array('kill', (array) $process->command, true));

    $this->artisan('dply:billing:enforce')->assertSuccessful();
    expect($idle->organization->fresh()->billing_paused_at)->not->toBeNull();
});

test('the 5-minute run skips paid, comped and already paused orgs', function () {
    config(['subscription.standard.trial.spending_limit_cents' => 1]);
    Notification::fake();
    [, , $comped] = app_site(['comped_until' => now()->addYear()]);
    [, , $paused] = app_site(['billing_paused_at' => now()->subHour()]);

    $this->artisan('dply:billing:enforce', ['--trialing' => true])->assertSuccessful();

    expect($comped->organization->fresh()->billing_paused_at)->toBeNull()
        ->and($paused->organization->fresh()->billing_notices)->toBeEmpty();
});

test('a trial whose card cannot be read gets none', function () {
    [, , $site] = app_site();

    expect(CaptureTrialCardFingerprint::refuseTrial($site->organization, '', true))->toBeTrue()
        ->and(CaptureTrialCardFingerprint::refuseTrial($site->organization, '', false))->toBeFalse();
});

test('the webhook reads the card from the customer when the subscription has none', function () {
    config(['cashier.secret' => 'sk_test_x']);
    Organization::factory()->create(['trial_card_fingerprint' => 'fp_1']);
    [, , $site] = app_site();
    $site->organization->forceFill(['stripe_id' => 'cus_2'])->save();
    $calls = [];
    $client = Mockery::mock(ClientInterface::class);
    $client->shouldReceive('request')->andReturnUsing(function ($method, $url, $headers, $params) use (&$calls) {
        $calls[] = [$method, $url, $params];
        $body = str_contains($url, '/customers/')
            ? ['id' => 'cus_2', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => ['id' => 'pm_1', 'object' => 'payment_method', 'card' => ['fingerprint' => 'fp_1']]]]
            : ['id' => 'sub_2', 'object' => 'subscription', 'status' => 'trialing', 'default_payment_method' => null];

        return [json_encode($body), 200, []];
    });
    ApiRequestor::setHttpClient($client);

    try {
        (new CaptureTrialCardFingerprint)->handle(new WebhookReceived(['type' => 'customer.subscription.created', 'data' => ['object' => ['id' => 'sub_2', 'customer' => 'cus_2']]]));
    } finally {
        ApiRequestor::setHttpClient(null);
    }

    expect(collect($calls)->last()[0])->toBe('post')
        ->and(collect($calls)->last()[2]['trial_end'])->toBe('now');
});

test('a trial app on a resident PHP server gets the size it needs to start', function () {
    [, , $site] = app_site(edge: BIG_APP);

    expect(EdgeContainerSettings::for($site, 'frankenphp')['instance_type'])->toBe(EdgeContainerSettings::minimumInstanceType($site, 'frankenphp'))
        ->and(EdgeContainerSettings::for($site, 'fpm')['instance_type'])->toBe(EdgeTrialLimits::containerType());
});
