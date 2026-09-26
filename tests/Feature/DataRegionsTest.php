<?php

declare(strict_types=1);

namespace Tests\Feature\DataRegionsTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Support\DataRegion;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use App\Modules\Edge\Support\EdgeValkey;
use App\Modules\Providers\Valkey\ValkeyRegions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'edge.valkey.api_url' => 'https://gw-nyc.test', 'edge.valkey.token' => 'nyc-token',
        'edge.valkey.domain' => 'cache.dply.test', 'edge.valkey.db_domain' => 'db.dply.test', 'edge.valkey.data_region' => 'ENAM',
        'edge.valkey.regions' => [
            ['key' => 'nyc3', 'label' => 'New York', 'cloudflare' => 'ENAM'],
            ['key' => 'sfo3', 'label' => 'San Francisco', 'cloudflare' => 'WNAM', 'api_url' => 'https://gw-sfo.test', 'token' => 'sfo-token', 'domain' => 'cache.sfo.dply.test', 'db_domain' => 'db.sfo.dply.test'],
        ],
    ]);
    Http::fake(['gw-nyc.test/*' => Http::response([]), 'gw-sfo.test/*' => Http::response([])]);
});

function app(array $edge = []): Site
{
    $org = Organization::factory()->create();

    return Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'meta' => ['edge' => array_replace_recursive(['runtime_mode' => 'container'], $edge)],
    ]);
}

test('regions come from config, the first built from the original settings', function () {
    expect(array_keys(ValkeyRegions::all()))->toBe(['nyc3', 'sfo3'])
        ->and(ValkeyRegions::default())->toBe('nyc3')
        ->and(ValkeyRegions::get('nyc3'))->toMatchArray(['api_url' => 'https://gw-nyc.test', 'domain' => 'cache.dply.test', 'cloudflare' => 'ENAM'])
        ->and(ValkeyRegions::forCloudflare('WNAM'))->toBe('sfo3')
        ->and(ValkeyRegions::forCloudflare('APAC'))->toBeNull()
        ->and(ValkeyRegions::get('nowhere')['key'])->toBe('nyc3');
});

test('a west-coast app gets its valkey and database in sfo3, and runs in WNAM', function () {
    $site = app(['container' => ['regions' => ['WNAM']]]);

    $valkey = EdgeValkey::provision($site, 'cache', 'flex_250m', 300);
    expect($valkey['target'])->toBe('valkey:sfo3:'.strtolower((string) $site->id).'-cache')
        ->and(EdgeValkey::tenantId($valkey['target']))->toBe(strtolower((string) $site->id).'-cache')
        ->and(EdgeValkey::region($valkey['target']))->toBe('sfo3')
        ->and(EdgeValkey::address($valkey['target']))->toBe(strtolower((string) $site->id).'-cache.cache.sfo.dply.test:6380')
        ->and($valkey['url'])->toContain('@'.strtolower((string) $site->id).'-cache.cache.sfo.dply.test:6380');

    $db = EdgeDplyDatabase::provision($site, 'xs', 300, 1);
    expect($db['region'])->toBe('sfo3')->and($db['host'])->toEndWith('.db.sfo.dply.test');

    Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://gw-sfo.test/tenants/') && $r->hasHeader('Authorization', 'Bearer sfo-token'));
    Http::assertNotSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://gw-nyc.test/'));

    // Data in sfo3 → the app runs in WNAM (once it no longer pins a region itself).
    $site->mergeEdgeMeta(['database' => ['engine' => 'postgres', 'provider' => 'dply', 'remote_id' => $db['id'], 'region' => 'sfo3'], 'container' => ['regions' => []]]);
    $site->save();
    expect(DataRegion::of($site))->toBe('sfo3')
        ->and(EdgeContainerSettings::constraints($site))->toBe(['regions' => ['WNAM']]);
});

test('records from before regions stay in the default region, unchanged', function () {
    $site = app(['connections' => [['kind' => 'redis', 'name' => 'REDIS', 'host' => 'redis.internal', 'target' => 'valkey:old-cache']]]);

    expect(EdgeValkey::region('valkey:old-cache'))->toBe('nyc3')
        ->and(EdgeValkey::tenantId('valkey:old-cache'))->toBe('old-cache')
        ->and(EdgeValkey::address('valkey:old-cache'))->toBe('old-cache.cache.dply.test:6380')
        ->and(DataRegion::of($site))->toBe('nyc3')
        ->and(EdgeContainerSettings::constraints($site))->toBe(['regions' => ['ENAM']]);

    // A new store for an app without a region goes to the default and keeps the old target shape.
    expect(EdgeValkey::provision(app(), 'cache', 'flex_250m', 300)['target'])->toStartWith('valkey:')->not->toContain('nyc3');
    Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://gw-nyc.test/tenants/'));
});

test('an EU-jurisdiction app goes to a European region when there is one', function () {
    config(['edge.valkey.regions' => [
        ['key' => 'nyc3', 'cloudflare' => 'ENAM'],
        ['key' => 'fra1', 'cloudflare' => 'WEUR', 'api_url' => 'https://gw-fra.test', 'token' => 't'],
    ]]);

    expect(DataRegion::forSite(app(['container' => ['jurisdiction' => 'eu']])))->toBe('fra1')
        ->and(DataRegion::forSite(app()))->toBe('nyc3');
});
