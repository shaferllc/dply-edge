<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Services\StarterTrafficGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

test('a trial past its credit pauses its managed container apps', function () {
    config(['subscription.standard.trial.spending_limit_cents' => 0, 'edge.fake.enabled' => true]);
    $org = Organization::factory()->create();
    $server = Server::factory()->for($org)->create();
    $site = Site::factory()->for($org)->for($server)->create([
        'meta' => ['edge' => ['runtime_mode' => 'container']],
    ]);

    app(StarterTrafficGate::class)->syncOrganization($org);

    expect(Cache::get('edge:fake:kv-text')[StarterTrafficGate::KEY_PREFIX.$site->id] ?? null)->toBe('1');
});

test('a trial under its credit clears the pause flag', function () {
    config(['edge.fake.enabled' => true]);
    $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(3)]);
    $server = Server::factory()->for($org)->create();
    $site = Site::factory()->for($org)->for($server)->create([
        'meta' => ['edge' => ['runtime_mode' => 'container']],
    ]);
    Cache::put('edge:fake:kv-text', [StarterTrafficGate::KEY_PREFIX.$site->id => '1'], now()->addDay());

    app(StarterTrafficGate::class)->syncOrganization($org);

    expect(Cache::get('edge:fake:kv-text')[StarterTrafficGate::KEY_PREFIX.$site->id] ?? null)->toBeNull();
});

test('a bring-your-own cloudflare container is not paused', function () {
    config(['subscription.standard.trial.spending_limit_cents' => 0, 'edge.fake.enabled' => true]);
    $org = Organization::factory()->create();
    $server = Server::factory()->for($org)->create();
    Site::factory()->for($org)->for($server)->create([
        'edge_backend' => 'org_cloudflare',
        'meta' => ['edge' => ['runtime_mode' => 'container']],
    ]);

    app(StarterTrafficGate::class)->syncOrganization($org);

    expect(Cache::get('edge:fake:kv-text', []))->toBe([]);
});

test('an org without a plan pauses its managed container apps', function () {
    config(['edge.fake.enabled' => true]);
    $org = Organization::factory()->noPlan()->create();
    $site = Site::factory()->for($org)->for(Server::factory()->for($org)->create())->create([
        'meta' => ['edge' => ['runtime_mode' => 'container']],
    ]);

    app(StarterTrafficGate::class)->syncOrganization($org);

    expect(Cache::get('edge:fake:kv-text')[StarterTrafficGate::KEY_PREFIX.$site->id] ?? null)->toBe('1');
});
