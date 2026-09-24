<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeSecurityPageTest;

use App\Enums\SiteType;
use App\Livewire\Sites\EdgeSettings;
use App\Models\EdgeAccessLog;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('edge security section summarizes this app only', function () {
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
                'live_url' => 'https://edge-app.on-dply.live',
                'routing' => [
                    'hostname' => 'edge-app.on-dply.live',
                    'custom_domains' => [
                        'www.example.com' => ['ssl_status' => 'active'],
                    ],
                ],
                'firewall' => ['country_mode' => 'block', 'countries' => ['CN']],
                'turnstile' => [
                    'enabled' => true,
                    'site_key' => 'site-key',
                    'secret_key' => 'secret',
                    'mode' => 'forms',
                ],
                'rate_limit' => ['enabled' => false, 'rules' => []],
            ],
        ],
    ]);

    EdgeAccessLog::query()->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'hostname' => 'edge-app.on-dply.live',
        'method' => 'GET',
        'path' => '/secret',
        'status_code' => 403,
        'country' => 'CN',
        'duration_ms' => 2,
        'occurred_at' => now()->subHour(),
    ]);
    EdgeAccessLog::query()->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'hostname' => 'edge-app.on-dply.live',
        'method' => 'GET',
        'path' => '/ok',
        'status_code' => 200,
        'duration_ms' => 8,
        'occurred_at' => now()->subMinutes(10),
    ]);

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'security']))
        ->assertOk()
        ->assertSee('Security', false);

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'security'])
        ->assertSee('edge-app.on-dply.live')
        ->assertSee('www.example.com')
        ->assertSee('TLS active')
        ->assertSee('1 country blocked')
        ->assertSee('Forms only')
        ->assertSee('/secret')
        ->assertDontSee('/ok');
});
