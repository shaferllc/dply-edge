<?php

declare(strict_types=1);

namespace Tests\Feature\SelfHosting\SelfHostingContainerTest;

use App\Http\Middleware\UseCloudflareClientIp;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function containerSite(array $attributes = []): Site
{
    $org = Organization::factory()->create();

    return Site::factory()->create(array_merge([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['runtime_mode' => 'container', 'build' => ['framework' => 'laravel']]],
    ], $attributes));
}

test('dply itself never migrates on boot, whatever the site setting says', function () {
    $self = containerSite(['git_repository_url' => 'https://github.com/shaferllc/dply-edge.git']);
    $customer = containerSite(['git_repository_url' => 'https://github.com/acme/shop.git']);
    $deployer = app(EdgeContainerDeployer::class);

    config(['edge.self.site_id' => null, 'edge.self.repo' => 'shaferllc/dply-edge']);
    expect($deployer->secrets($self, [], [], true)['DPLY_MIGRATE_ON_BOOT'])->toBe('0')
        ->and($deployer->secrets($customer, [], [], true)['DPLY_MIGRATE_ON_BOOT'])->toBe('1')
        // The app's own env cannot turn it back on: control keys merge last.
        ->and($deployer->secrets($self, ['DPLY_MIGRATE_ON_BOOT' => '1'], [], true)['DPLY_MIGRATE_ON_BOOT'])->toBe('0');

    // DPLY_SELF_SITE_ID pins it to one site by id.
    config(['edge.self.site_id' => (string) $customer->id]);
    expect(EdgeContainerDeployer::isSelfSite($customer))->toBeTrue()
        ->and(EdgeContainerDeployer::isSelfSite($self))->toBeFalse();
});

test('/up answers without touching the database', function () {
    DB::enableQueryLog();

    $this->get('/up')->assertOk();

    expect(DB::getQueryLog())->toBe([]);
});

test('in a container the visitor address comes from CF-Connecting-IP', function () {
    config(['dply_runtime.mode' => 'container']);
    Request::setTrustedProxies(['0.0.0.0/0', '::/0'], Request::HEADER_X_FORWARDED_FOR);
    $request = Request::create('/', 'GET', server: [
        'REMOTE_ADDR' => '10.0.0.7',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
        'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.1',
    ]);

    $ip = (new UseCloudflareClientIp)->handle($request, fn (Request $r) => response($r->ip()))->getContent();

    expect($ip)->toBe('203.0.113.9');
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

test('logos on R2 are served at the URL the disk hands out', function () {
    $s3 = [
        'driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'auto', 'bucket' => 'b',
        'endpoint' => 'https://acct.r2.cloudflarestorage.com', 'use_path_style_endpoint' => true,
        'root' => '_platform/site-assets', 'url' => 'http://localhost/site-assets',
    ];
    config(['filesystems.disks.site_assets' => $s3]);
    require base_path('routes/web.php'); // registers site-assets.stream now that the disk is not local
    app('router')->getRoutes()->refreshNameLookups();

    $url = Storage::build($s3)->url('org-logos/acme.png');
    Storage::set('site_assets', Storage::build(['driver' => 'local', 'root' => storage_path('framework/testing/site-assets')]));
    Storage::disk('site_assets')->put('org-logos/acme.png', 'PNGDATA');

    $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertStreamedContent('PNGDATA');
    $this->get('/site-assets/_platform/site-assets/../../.env')->assertNotFound();
    Storage::disk('site_assets')->deleteDirectory('org-logos');
});
