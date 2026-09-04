<?php

declare(strict_types=1);

namespace Tests\Feature\ContainerSiteFieldsTest;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('container columns persist', function () {
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $user->id]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'type' => SiteType::Container,
        'runtime' => null,
        'container_image' => 'ghcr.io/acme/api:v1',
        'container_registry' => 'ghcr.io',
        'container_port' => 8080,
        'container_backend' => 'digitalocean_app_platform',
        'container_backend_id' => 'app-12345',
        'container_region' => 'nyc',
    ]);

    $fresh = $site->fresh();
    expect($fresh->container_image)->toBe('ghcr.io/acme/api:v1');
    expect($fresh->container_port)->toBe(8080);
    expect($fresh->container_backend)->toBe('digitalocean_app_platform');
    expect($fresh->container_backend_id)->toBe('app-12345');
    expect($fresh->container_region)->toBe('nyc');
    expect($fresh->usesContainerRuntime())->toBeTrue();
});
test('container runtime helper handles legacy backend field', function () {
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $user->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'type' => SiteType::Php,
        'container_backend' => 'aws_app_runner',
    ]);

    expect($site->fresh()->usesContainerRuntime())->toBeTrue();
});
test('php site does not use container runtime', function () {
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $user->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'type' => SiteType::Php,
    ]);

    expect($site->fresh()->usesContainerRuntime())->toBeFalse();
});
test('container live url reads from meta', function () {
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $user->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'type' => SiteType::Container,
        'meta' => ['container' => ['live_url' => 'https://api-acme.ondigitalocean.app']],
    ]);

    expect($site->fresh()->containerLiveUrl())->toBe('https://api-acme.ondigitalocean.app');
});
