<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Edge\EdgeProductionEnvTest;

use App\Models\EdgeSiteEnvVar;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Services\EdgeProductionEnv;
use App\Support\Sites\OrganizationSecretManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('edge env vars win over linked org secrets', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'edge_backend' => 'dply_edge',
    ]);

    $manager = app(OrganizationSecretManager::class);
    $secret = $manager->create($org, 'SHARED_KEY', 'from-vault', null);
    $only = $manager->create($org, 'VAULT_ONLY', 'vault', null);
    $manager->link($site, $secret);
    $manager->link($site, $only);

    EdgeSiteEnvVar::query()->create([
        'site_id' => $site->id,
        'key' => 'SHARED_KEY',
        'value' => 'from-edge',
        'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION,
    ]);

    $env = app(EdgeProductionEnv::class)->forSite($site->fresh());

    expect($env['SHARED_KEY'])->toBe('from-edge')
        ->and($env['VAULT_ONLY'])->toBe('vault');
});

test('previews inherit the parent env and linked secrets, preview rows override', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);
    $parent = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'edge_backend' => 'dply_edge',
    ]);
    $preview = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['preview_parent_site_id' => $parent->id]],
    ]);

    $manager = app(OrganizationSecretManager::class);
    $manager->link($parent, $manager->create($org, 'VAULT_KEY', 'vault', null));

    foreach ([[$parent, 'API_URL', 'https://prod'], [$parent, 'VITE_NAME', 'prod'], [$preview, 'API_URL', 'https://staging'],
        [$parent, 'DATABASE_URL', 'postgres://prod'], [$parent, 'DB_PASSWORD', 'prod-secret'], [$parent, 'REDIS_URL', 'rediss://prod'],
        [$preview, 'REDIS_URL', 'rediss://preview']] as [$site, $key, $value]) {
        EdgeSiteEnvVar::query()->create([
            'site_id' => $site->id,
            'key' => $key,
            'value' => $value,
            'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION,
        ]);
    }

    $env = app(EdgeProductionEnv::class)->forSite($preview->fresh());

    expect($env)->toMatchArray([
        'VAULT_KEY' => 'vault',
        'VITE_NAME' => 'prod',
        'API_URL' => 'https://staging',
        // The preview's own explicit value still applies.
        'REDIS_URL' => 'rediss://preview',
    ])
        // Production's database never reaches a preview.
        ->not->toHaveKey('DATABASE_URL')
        ->not->toHaveKey('DB_PASSWORD');
});
