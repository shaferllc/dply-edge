<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Routing;
use App\Models\EdgeDnsZone;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Jobs\CheckEdgeDnsZonesJob;
use App\Modules\Edge\Services\EdgeCustomDomainProvisioner;
use App\Modules\Edge\Services\EdgeDnsZones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['edge.dns.enabled' => true]));

function dnsZoneSite(): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Edge App',
        'slug' => 'edge-app',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['runtime_profile' => 'edge_web', 'edge' => [
            'source' => ['repo' => 'acme/web', 'branch' => 'main'],
            'routing' => ['hostname' => 'edge-app.dply.host'],
            'live_url' => 'https://edge-app.dply.host',
            'active_deployment_id' => 'dep-1',
        ]],
    ]);
}

test('adding a domain creates a Cloudflare zone and keeps the nameservers it returns', function () {
    config([
        'edge.fake.enabled' => false,
        'edge.cloudflare.account_id' => 'acct',
        'edge.cloudflare.api_token' => 'tok',
        'edge.dns.nameserver_set' => 1,
    ]);
    Http::fake([
        'api.cloudflare.com/client/v4/zones' => Http::response(['success' => true, 'result' => [
            'id' => 'zone-1', 'status' => 'pending',
            'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'],
            'original_name_servers' => ['ns1.registrar.test'],
        ]]),
        'api.cloudflare.com/client/v4/zones/zone-1/dns_settings' => Http::response(['success' => true, 'result' => []]),
        'api.cloudflare.com/client/v4/zones/zone-1' => Http::response(['success' => true, 'result' => ['id' => 'zone-1', 'name_servers' => ['ns1.dply.io', 'ns2.dply.io']]]),
        'api.cloudflare.com/client/v4/zones/zone-1/dns_records/scan/trigger' => Http::response(['success' => true, 'result' => []]),
    ]);
    $site = dnsZoneSite();

    $zone = app(EdgeDnsZones::class)->add($site->organization, 'www.example.com');

    expect($zone->name)->toBe('example.com')
        ->and($zone->status)->toBe('pending')
        ->and($zone->name_servers)->toBe(['ns1.dply.io', 'ns2.dply.io'])
        ->and($zone->original_name_servers)->toBe(['ns1.registrar.test']);
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/zones') && $r['account']['id'] === 'acct' && $r['name'] === 'example.com');
    Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/dns_settings') && $r['nameservers'] === ['type' => 'custom.account', 'ns_set' => 1]);
});

test('a domain dply runs for another organization cannot be claimed', function () {
    config(['edge.fake.enabled' => true]);
    $first = dnsZoneSite();
    app(EdgeDnsZones::class)->add($first->organization, 'example.com');

    $other = Organization::factory()->create();

    expect(fn () => app(EdgeDnsZones::class)->add($other, 'shop.example.com'))
        ->toThrow(RuntimeException::class, 'another organization');
});

test('a pending zone never provisions a hostname; an active one does, with no records to copy', function () {
    config(['edge.fake.enabled' => true]);
    $site = dnsZoneSite();
    $zones = app(EdgeDnsZones::class);
    $zone = $zones->add($site->organization, 'example.com');

    $entry = app(EdgeCustomDomainProvisioner::class)->provision($site->fresh(), 'www.example.com');
    expect($entry['mode'])->not->toBe('managed')
        ->and($entry['dns_status'])->toBe('pending');

    // The customer switches nameservers; the job finds the zone active.
    (new CheckEdgeDnsZonesJob)->handle($zones, app(EdgeCustomDomainProvisioner::class));

    $entry = $site->fresh()->edgeMeta()['routing']['custom_domains']['www.example.com'];
    expect($zone->fresh()->status)->toBe('active')
        ->and($entry['mode'])->toBe('managed')
        ->and($entry['dns_status'])->toBe('ready');
    expect(collect($zones->records($zone->fresh()))->firstWhere('name', 'www.example.com'))
        ->toMatchArray(['type' => 'CNAME', 'content' => 'edge-app.dply.host']);
});

test('use your own domain with dply DNS attaches the domain and shows the nameservers', function () {
    config(['edge.fake.enabled' => true]);
    $site = dnsZoneSite();

    Livewire::actingAs($site->organization->users()->first())
        ->test(Routing::class, ['server' => $site->server, 'site' => $site])
        ->call('openAddDomain')
        ->set('addHost', 'www.example.com')
        ->set('addWay', 'dply')
        ->call('continueAddDomain')
        ->assertHasNoErrors()
        ->assertSee('ns1.dply.io')
        ->assertSee('Turn off DNSSEC')
        ->assertSee('goes live once example.com’s nameservers point at dply', false);

    expect(EdgeDnsZone::query()->where('name', 'example.com')->exists())->toBeTrue()
        ->and($site->fresh()->edgeMeta()['routing']['custom_domains'])->toHaveKey('www.example.com');
});

test('a preview holding its parent\'s domain never repoints it when the zone activates', function () {
    config(['edge.fake.enabled' => true]);
    $parent = dnsZoneSite();
    $zones = app(EdgeDnsZones::class);
    $zone = $zones->add($parent->organization, 'example.com');
    app(EdgeCustomDomainProvisioner::class)->provision($parent->fresh(), 'www.example.com');

    $preview = Site::factory()->create([
        'server_id' => $parent->server_id,
        'user_id' => $parent->user_id,
        'organization_id' => $parent->organization_id,
        'name' => 'Preview',
        'slug' => 'edge-app-pr-1',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => [
            'preview_parent_site_id' => (string) $parent->id,
            'routing' => ['hostname' => 'edge-app-pr-1.dply.host', 'custom_domains' => ['www.example.com' => ['dns_status' => 'pending']]],
            'live_url' => 'https://edge-app-pr-1.dply.host',
            'active_deployment_id' => 'dep-2',
        ]],
    ]);

    (new CheckEdgeDnsZonesJob)->handle($zones, app(EdgeCustomDomainProvisioner::class));
    app(EdgeCustomDomainProvisioner::class)->provision($preview->fresh(), 'www.example.com');

    expect(collect($zones->records($zone->fresh()))->where('name', 'www.example.com')->pluck('content')->all())
        ->toBe(['edge-app.dply.host']);
});

test('a redirect opens in its dialog, saves in place, and reads as a sentence', function () {
    config(['edge.fake.enabled' => true]);
    $site = dnsZoneSite();

    Livewire::actingAs($site->organization->users()->first())
        ->test(Routing::class, ['server' => $site->server, 'site' => $site])
        ->call('setTab', 'redirects')
        ->call('openRule', 'redirects')
        ->set('new_redirect_from', '/old')
        ->set('new_redirect_to', '/new')
        ->call('saveRule')
        ->assertSee('/old moves permanently to /new')
        ->call('openRule', 'redirects', 0)
        ->assertSet('new_redirect_from', '/old')
        ->set('new_redirect_to', '/newer')
        ->set('new_redirect_status', 302)
        ->call('saveRule')
        ->assertSet('dashboard_redirects', [['from' => '/old', 'to' => '/newer', 'status' => 302]])
        ->assertSee('/old moves temporarily to /newer');
});
