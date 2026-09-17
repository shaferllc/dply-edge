<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeContainerSettingsTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Container;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Support\SiteSettingsSidebar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function containerSite(string $runtime = 'container'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id, 'type' => SiteType::Static,
        'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => $runtime]],
    ]);

    return [$user, $server, $site];
}

test('the container tab only appears for container sites', function () {
    [, $server, $container] = containerSite();
    [, $staticServer, $static] = containerSite('static');

    $ids = fn (Site $site, Server $server) => collect(SiteSettingsSidebar::items($site, $server))->pluck('id')->all();

    expect($ids($container, $server))->toContain('edge-container')
        ->and($ids($static, $staticServer))->not->toContain('edge-container');
});

test('saved settings reach the generated wrangler config and worker', function () {
    [$user, $server, $site] = containerSite();

    Livewire::actingAs($user)
        ->test(Container::class, ['server' => $server, 'site' => $site])
        ->set('instance_type', 'standard-2')
        ->set('max_instances', 8)
        ->set('sleep_after', '30m')
        ->set('jurisdiction', 'eu')
        ->call('save')
        ->assertHasNoErrors();

    $dir = sys_get_temp_dir().'/dply-container-settings-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site->fresh(), '/x/Dockerfile', 8080, []);
    $config = json_decode(File::get($dir.'/wrangler.jsonc'), true);
    $worker = File::get($dir.'/src/index.js');
    File::deleteDirectory($dir);

    expect($config['containers'][0])->toMatchArray(['instance_type' => 'standard-2', 'max_instances' => 8, 'constraints' => ['jurisdiction' => 'eu']])
        ->and($worker)->toContain('sleepAfter = "30m"')
        ->and($worker)->toContain('getRandom(env.APP, 8)');
});

test('invalid sizes are rejected', function () {
    [$user, $server, $site] = containerSite();

    Livewire::actingAs($user)
        ->test(Container::class, ['server' => $server, 'site' => $site])
        ->set('instance_type', 'huge')
        ->set('max_instances', 99)
        ->call('save')
        ->assertHasErrors(['instance_type', 'max_instances']);
});
