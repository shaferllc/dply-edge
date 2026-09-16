<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('migrate hostnames dry run lists legacy dply.host edge sites', function () {
    config(['edge.testing_domains' => ['on-dply.live']]);

    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'meta' => [
            'edge' => [
                'routing' => ['hostname' => 'legacy-abc123.dply.host'],
                'live_url' => 'https://legacy-abc123.dply.host',
            ],
        ],
    ]);

    $this->artisan('dply:edge:migrate-hostnames', ['--dry-run' => true])
        ->expectsOutputToContain('legacy-abc123.dply.host → legacy-abc123.on-dply.live')
        ->assertOk();
});

test('migrate hostnames updates site meta to on-dply.live', function () {
    config(['edge.testing_domains' => ['on-dply.live']]);

    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'meta' => [
            'edge' => [
                'routing' => ['hostname' => 'legacy-abc123.dply.host'],
                'live_url' => 'https://legacy-abc123.dply.host',
            ],
        ],
    ]);

    $this->artisan('dply:edge:migrate-hostnames', ['--site' => $site->id])->assertOk();

    $site->refresh();
    expect($site->edgeHostname())->toBe('legacy-abc123.on-dply.live');
    expect($site->edgeLiveUrl())->toBe('https://legacy-abc123.on-dply.live');
});

test('migrate hostnames moves on-dply.site edge sites to on-dply.live', function () {
    config(['edge.testing_domains' => ['on-dply.live']]);

    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'meta' => [
            'edge' => [
                'routing' => ['hostname' => 'portfolio-vbapon.on-dply.site'],
                'live_url' => 'https://portfolio-vbapon.on-dply.site',
            ],
        ],
    ]);

    $this->artisan('dply:edge:migrate-hostnames', ['--site' => $site->id])->assertOk();

    expect($site->refresh()->edgeHostname())->toBe('portfolio-vbapon.on-dply.live');
});
