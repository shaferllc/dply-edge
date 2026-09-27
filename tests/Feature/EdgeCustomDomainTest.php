<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeCustomDomainTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Domains;
use App\Livewire\Sites\Edge\Workspace\Routing;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Jobs\VerifyEdgeCustomDomainsJob;
use App\Modules\Edge\Services\EdgeCustomDomainProvisioner;
use App\Modules\Edge\Services\EdgeDeliveryContextResolver;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use App\Modules\Edge\Services\EdgeRouter;
use App\Modules\Notifications\Services\NotificationPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('attach custom domain starts in pending dns state with fake ssl active', function () {
    config([
        'edge.fake.enabled' => true,
        'edge.custom_hostnames.enabled' => true,
    ]);
    $site = makeLiveEdgeSite();

    $backend = EdgeRouter::backendFor($site);
    expect($backend)->not->toBeNull();

    $backend->attachDomain($site->fresh(), 'www.example.com');

    $site->refresh();
    $domains = $site->edgeMeta()['routing']['custom_domains'] ?? [];
    expect($domains)->toHaveKey('www.example.com');
    expect($domains['www.example.com']['dns_status'] ?? null)->toBe('pending');
    expect($domains['www.example.com']['cname_target'] ?? '')->not->toBe('');
    expect($domains['www.example.com']['ssl_status'] ?? null)->toBe('active');
    expect($domains['www.example.com']['cf_custom_hostname_id'] ?? '')->not->toBe('');
});

test('verify fails when dns records are missing', function () {
    config([
        'edge.fake.enabled' => true,
        'edge.custom_hostnames.enabled' => true,
    ]);
    $site = makeLiveEdgeSite();
    $provisioner = app(EdgeCustomDomainProvisioner::class);

    $provisioner->provision($site->fresh(), 'docs.example.com');
    $entry = $provisioner->verify($site->fresh(), 'docs.example.com');

    expect($entry['dns_status'] ?? null)->toBe('failed');
});

test('fake backend detaches custom domain', function () {
    config([
        'edge.fake.enabled' => true,
        'edge.custom_hostnames.enabled' => true,
    ]);
    $site = makeLiveEdgeSite();

    $backend = EdgeRouter::backendFor($site);
    $backend->attachDomain($site->fresh(), 'docs.example.com');
    $backend->detachDomain($site->fresh(), 'docs.example.com');

    $site->refresh();
    $domains = $site->edgeMeta()['routing']['custom_domains'] ?? [];
    expect($domains)->not->toHaveKey('docs.example.com');
});

test('managed custom hostname create is called when fake edge is off', function () {
    config([
        'edge.fake.enabled' => false,
        'edge.custom_hostnames.enabled' => true,
        'edge.cloudflare.account_id' => 'acct_test',
        'edge.cloudflare.api_token' => 'token_test',
        'edge.cloudflare.worker_zone_name' => 'on-dply.site',
    ]);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone_123/custom_hostnames*' => function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['success' => true, 'result' => []]);
            }

            if ($request->method() === 'POST') {
                return Http::response([
                    'success' => true,
                    'result' => [
                        'id' => 'ch_abc',
                        'hostname' => 'api.example.com',
                        'status' => 'pending',
                        'ssl' => ['status' => 'pending_validation', 'method' => 'http', 'type' => 'dv'],
                        'ownership_verification' => [
                            'type' => 'txt',
                            'name' => '_cf-custom-hostname.api.example.com',
                            'value' => 'ownership-token',
                        ],
                    ],
                ]);
            }

            return Http::response(['success' => true, 'result' => []]);
        },
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone_123', 'name' => 'on-dply.site']],
        ]),
    ]);

    $site = makeLiveEdgeSite();
    $provisioner = app(EdgeCustomDomainProvisioner::class);
    $entry = $provisioner->provision($site->fresh(), 'api.example.com');

    expect($entry['cf_custom_hostname_id'] ?? null)->toBe('ch_abc');
    expect($entry['ssl_status'] ?? null)->toBe('pending');
    expect($entry['ownership_verification']['value'] ?? null)->toBe('ownership-token');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/custom_hostnames')
            && ($request['hostname'] ?? null) === 'api.example.com';
    });
});

test('remove deletes custom hostname on cloudflare', function () {
    config([
        'edge.fake.enabled' => false,
        'edge.custom_hostnames.enabled' => true,
        'edge.cloudflare.account_id' => 'acct_test',
        'edge.cloudflare.api_token' => 'token_test',
        'edge.cloudflare.worker_zone_name' => 'on-dply.site',
    ]);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone_123/custom_hostnames/ch_del' => Http::response([
            'success' => true,
            'result' => ['id' => 'ch_del'],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone_123/custom_hostnames*' => Http::response([
            'success' => true,
            'result' => [
                'id' => 'ch_del',
                'hostname' => 'gone.example.com',
                'ssl' => ['status' => 'active'],
            ],
        ]),
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone_123', 'name' => 'on-dply.site']],
        ]),
    ]);

    $site = makeLiveEdgeSite();
    $meta = $site->edgeMeta();
    $meta['routing']['custom_domains']['gone.example.com'] = [
        'hostname' => 'gone.example.com',
        'mode' => 'manual',
        'dns_status' => 'ready',
        'cname_target' => 'edge-app.dply.host',
        'cf_custom_hostname_id' => 'ch_del',
        'ssl_status' => 'active',
    ];
    $site->update(['meta' => array_merge(is_array($site->meta) ? $site->meta : [], ['edge' => $meta])]);

    app(EdgeCustomDomainProvisioner::class)->remove($site->fresh(), 'gone.example.com');

    Http::assertSent(function ($request) {
        return $request->method() === 'DELETE'
            && str_contains($request->url(), '/custom_hostnames/ch_del');
    });

    $site->refresh();
    expect($site->edgeMeta()['routing']['custom_domains'] ?? [])->not->toHaveKey('gone.example.com');
});

test('org cloudflare backend skips custom hostnames', function () {
    config([
        'edge.fake.enabled' => true,
        'edge.custom_hostnames.enabled' => true,
    ]);

    $site = makeLiveEdgeSite(['edge_backend' => 'org_cloudflare']);
    $provisioner = app(EdgeCustomDomainProvisioner::class);
    $entry = $provisioner->provision($site->fresh(), 'byo.example.com');

    expect($entry['ssl_status'] ?? null)->toBeNull();
    expect($entry['cf_custom_hostname_id'] ?? null)->toBeNull();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function makeLiveEdgeSite(array $overrides = []): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $site = Site::factory()->create(array_merge([
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
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'routing' => ['hostname' => 'edge-app.dply.host', 'spa_fallback' => true],
                'live_url' => 'https://edge-app.dply.host',
            ],
        ],
    ], $overrides));

    $deployment = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'storage_prefix' => 'edge/test/'.$site->id,
        'published_at' => now(),
    ]);

    $meta = $site->edgeMeta();
    $meta['active_deployment_id'] = $deployment->id;
    $site->update(['meta' => array_merge(is_array($site->meta) ? $site->meta : [], ['edge' => $meta])]);

    return $site->fresh();
}

/** A provisioner whose DNS lookups return the given records per [host][type]. */
function provisionerWithDns(array $records): EdgeCustomDomainProvisioner
{
    return new EdgeCustomDomainProvisioner(
        app(EdgeHostMapPublisher::class),
        app(EdgeDeliveryContextResolver::class),
        fn (string $host, int $type): array => $records[$host][$type] ?? [],
    );
}

test('a hostname attached to one site cannot be attached to a site in another org', function () {
    config(['edge.fake.enabled' => true, 'edge.custom_hostnames.enabled' => true]);
    $owner = makeLiveEdgeSite();
    $attacker = makeLiveEdgeSite(['slug' => 'edge-app-2']);
    $provisioner = app(EdgeCustomDomainProvisioner::class);

    $provisioner->provision($owner->fresh(), 'shop.example.com');

    expect(fn () => $provisioner->provision($attacker->fresh(), 'SHOP.example.com.'))
        ->toThrow(\RuntimeException::class, 'already attached to another site');
    expect($attacker->fresh()->edgeMeta()['routing']['custom_domains'] ?? [])->toBe([]);
});

test('the host map never publishes a hostname another site holds ready', function () {
    config(['edge.fake.enabled' => true]);
    $owner = makeLiveEdgeSite();
    $dupe = makeLiveEdgeSite(['slug' => 'edge-app-2']);
    foreach ([$owner, $dupe] as $site) {
        $meta = $site->edgeMeta();
        $meta['routing']['custom_domains']['shop.example.com'] = ['hostname' => 'shop.example.com', 'dns_status' => 'ready'];
        $site->update(['meta' => array_merge($site->meta, ['edge' => $meta])]);
    }
    Cache::forget('edge:fake:host-map');

    $dupe = $dupe->fresh();
    app(EdgeHostMapPublisher::class)->publish($dupe, EdgeDeployment::query()->findOrFail($dupe->edgeMeta()['active_deployment_id']));

    expect(Cache::get('edge:fake:host-map', []))->not->toHaveKey('shop.example.com')
        ->toHaveKey('edge-app.dply.host');
});

test('a pending zone in the org cloudflare account does not auto-verify a hostname', function () {
    config(['edge.fake.enabled' => false, 'edge.custom_hostnames.enabled' => false]);
    $site = makeLiveEdgeSite();
    ProviderCredential::factory()->create([
        'organization_id' => $site->organization_id,
        'provider' => 'cloudflare',
        'credentials' => ['api_token' => 'cf-token'],
    ]);
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones?*' => fn ($request) => Http::response([
            'success' => true,
            'result' => ($request['status'] ?? null) === 'active' ? [] : [['id' => 'zone_victim', 'name' => 'victim.com', 'status' => 'pending']],
        ]),
        '*' => Http::response(['success' => true, 'result' => []]),
    ]);

    $entry = app(EdgeCustomDomainProvisioner::class)->provision($site->fresh(), 'www.victim.com');

    expect($entry['mode'])->toBe('manual')->and($entry['dns_status'])->toBe('pending');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/dns_records'));
});

test('a cname to the shared fallback origin needs the site txt token to verify', function () {
    config([
        'edge.fake.enabled' => true,
        'edge.custom_hostnames.enabled' => true,
        'edge.custom_hostnames.fallback_origin' => 'fallback.on-dply.site',
    ]);
    $site = makeLiveEdgeSite();
    $cname = [DNS_CNAME | DNS_A | DNS_AAAA => [['type' => 'CNAME', 'target' => 'fallback.on-dply.site']]];

    $entry = provisionerWithDns(['www.victim.com' => $cname])->provision($site->fresh(), 'www.victim.com');
    $proof = $entry['dply_verification'];
    expect($proof['name'])->toBe('_dply-verify.www.victim.com');

    $entry = provisionerWithDns(['www.victim.com' => $cname])->verify($site->fresh(), 'www.victim.com');
    expect($entry['dns_status'])->toBe('failed')->and($entry['error'])->toContain($proof['value']);

    $entry = provisionerWithDns([
        'www.victim.com' => $cname,
        '_dply-verify.www.victim.com' => [DNS_TXT => [['type' => 'TXT', 'txt' => $proof['value']]]],
    ])->verify($site->fresh(), 'www.victim.com');
    expect($entry['dns_status'])->toBe('ready')->and($entry['ownership_verified_at'])->not->toBeNull();

    // Grandfathered: a domain that was already ready keeps verifying without the TXT record.
    $meta = $site->fresh()->edgeMeta();
    $meta['routing']['custom_domains']['old.example.com'] = ['hostname' => 'old.example.com', 'dns_status' => 'ready'];
    $site->update(['meta' => array_merge($site->fresh()->meta, ['edge' => $meta])]);
    $entry = provisionerWithDns(['old.example.com' => $cname])->verify($site->fresh(), 'old.example.com');
    expect($entry['dns_status'])->toBe('ready');
});

test('verify ignores a hostname the site never attached', function () {
    config(['edge.fake.enabled' => true]);
    $site = makeLiveEdgeSite();

    expect(app(EdgeCustomDomainProvisioner::class)->verify($site->fresh(), 'never.example.com'))->toBeNull();
    expect($site->fresh()->edgeMeta()['routing']['custom_domains'] ?? [])->toBe([]);
});

test('custom domains are capped across the organization', function () {
    config(['edge.fake.enabled' => true]);
    $site = makeLiveEdgeSite();
    $second = makeLiveEdgeSite(['slug' => 'edge-app-2', 'organization_id' => $site->organization_id, 'server_id' => $site->server_id]);
    config(['subscription.standard.tiers.'.$site->organization->billingTier().'.custom_domains' => 1]);
    $provisioner = app(EdgeCustomDomainProvisioner::class);

    $provisioner->provision($site->fresh(), 'one.example.com');
    $provisioner->provision($site->fresh(), 'one.example.com'); // re-provisioning is not a new domain

    expect(fn () => $provisioner->provision($second->fresh(), 'two.example.com'))
        ->toThrow(\RuntimeException::class, 'across the organization');
});

test('a preview never publishes, unpublishes or verifies its parent custom domains', function () {
    config(['edge.fake.enabled' => true]);
    $parent = makeLiveEdgeSite();
    $meta = $parent->edgeMeta();
    $meta['routing']['custom_domains']['www.example.com'] = ['hostname' => 'www.example.com', 'dns_status' => 'ready'];
    $parent->update(['meta' => array_merge($parent->meta, ['edge' => $meta])]);
    Cache::put('edge:fake:host-map', ['www.example.com' => ['site_id' => (string) $parent->id]]);

    // Previews copy the parent's routing, custom domains included.
    $preview = makeLiveEdgeSite(['slug' => 'edge-app-pr-1', 'organization_id' => $parent->organization_id, 'server_id' => $parent->server_id]);
    $previewMeta = $preview->edgeMeta();
    $previewMeta['routing']['custom_domains'] = $meta['routing']['custom_domains'];
    $previewMeta['preview_parent_site_id'] = $parent->id;
    $preview->update(['meta' => array_merge($preview->meta, ['edge' => $previewMeta])]);
    $preview = $preview->fresh();

    $publisher = app(EdgeHostMapPublisher::class);
    $publisher->publish($preview, EdgeDeployment::query()->findOrFail($preview->edgeMeta()['active_deployment_id']));
    expect(Cache::get('edge:fake:host-map')['www.example.com'])->toBe(['site_id' => (string) $parent->id]);

    $publisher->unpublish($preview);
    expect(Cache::get('edge:fake:host-map'))->toHaveKey('www.example.com');

    expect(app(EdgeCustomDomainProvisioner::class)->verify($preview, 'www.example.com'))->toBeNull();
});

test('the domains pages show the verification txt record until the domain is ready', function () {
    config(['edge.fake.enabled' => true]);
    $site = makeLiveEdgeSite();
    $proof = app(EdgeCustomDomainProvisioner::class)->provision($site->fresh(), 'www.example.com')['dply_verification'];
    $user = $site->organization->users()->first();

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $site->server_id, 'site' => $site->id, 'section' => 'routing', 'tab' => 'domains']))
        ->assertOk()
        ->assertSee($proof['name'])
        ->assertSee($proof['value']);

    Livewire::actingAs($user)
        ->test(Domains::class, ['server' => $site->server, 'site' => $site->fresh()])
        ->assertSee($proof['value']);
});

test('a flattened apex verifies by address match plus the site txt token', function () {
    config([
        'edge.fake.enabled' => true,
        'edge.custom_hostnames.enabled' => true,
        'edge.custom_hostnames.fallback_origin' => 'fallback.on-dply.site',
    ]);
    $site = makeLiveEdgeSite();
    $dns = [
        'victim.com' => [DNS_CNAME | DNS_A | DNS_AAAA => [['type' => 'A', 'ip' => '104.18.1.1']]],
        'fallback.on-dply.site' => [DNS_A | DNS_AAAA => [['type' => 'A', 'ip' => '104.18.1.1'], ['type' => 'AAAA', 'ipv6' => '2606:4700::1']]],
    ];

    $proof = provisionerWithDns($dns)->provision($site->fresh(), 'victim.com')['dply_verification'];

    $entry = provisionerWithDns($dns)->verify($site->fresh(), 'victim.com');
    expect($entry['dns_status'])->toBe('failed')->and($entry['error'])->toContain($proof['value']);

    $dns['_dply-verify.victim.com'] = [DNS_TXT => [['type' => 'TXT', 'txt' => $proof['value']]]];
    expect(provisionerWithDns($dns)->verify($site->fresh(), 'victim.com')['dns_status'])->toBe('ready');

    // Addresses that aren't the edge's don't match.
    $dns['other.com'] = [DNS_CNAME | DNS_A | DNS_AAAA => [['type' => 'A', 'ip' => '203.0.113.9']]];
    provisionerWithDns($dns)->provision($site->fresh(), 'other.com');
    expect(provisionerWithDns($dns)->verify($site->fresh(), 'other.com')['dns_status'])->toBe('failed');
});

test('a flattened apex needs the txt token even without the shared fallback origin', function () {
    config(['edge.fake.enabled' => true, 'edge.custom_hostnames.enabled' => false]);
    $site = makeLiveEdgeSite();
    $dns = [
        'apex.dev' => [DNS_CNAME | DNS_A | DNS_AAAA => [['type' => 'A', 'ip' => '104.18.2.2']]],
        'edge-app.dply.host' => [DNS_A | DNS_AAAA => [['type' => 'A', 'ip' => '104.18.2.2']]],
    ];

    provisionerWithDns($dns)->provision($site->fresh(), 'apex.dev');

    expect(provisionerWithDns($dns)->verify($site->fresh(), 'apex.dev')['error'])->toContain('_dply-verify.apex.dev');
});

test('auto dns finds an apex zone and writes the record at the zone root', function () {
    config(['edge.fake.enabled' => false, 'edge.custom_hostnames.enabled' => false]);
    $site = makeLiveEdgeSite();
    ProviderCredential::factory()->create([
        'organization_id' => $site->organization_id,
        'provider' => 'cloudflare',
        'credentials' => ['api_token' => 'cf-token'],
    ]);
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones?*' => fn ($request) => Http::response([
            'success' => true,
            'result' => ($request['name'] ?? null) === 'example.com' ? [['id' => 'zone_apex', 'name' => 'example.com', 'status' => 'active']] : [],
        ]),
        '*' => Http::response(['success' => true, 'result' => []]),
    ]);

    app(EdgeCustomDomainProvisioner::class)->provision($site->fresh(), 'example.com');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/zones/zone_apex/dns_records')
        && $request['name'] === 'example.com'
        && $request['type'] === 'CNAME');
    expect($site->fresh()->edgeMeta()['routing']['custom_domains']['example.com']['record_name'] ?? null)->toBe('@');
});

test('failed domains are re-checked with backoff for a bounded period', function () {
    $at = fn (string $attached, ?string $checked = null): array => array_filter([
        'attached_at' => now()->sub($attached)->toIso8601String(),
        'verified_at' => $checked === null ? null : now()->sub($checked)->toIso8601String(),
    ]);

    expect(VerifyEdgeCustomDomainsJob::failedRecheckDue($at('30 minutes', '15 minutes')))->toBeTrue()
        ->and(VerifyEdgeCustomDomainsJob::failedRecheckDue($at('3 hours', '20 minutes')))->toBeFalse()
        ->and(VerifyEdgeCustomDomainsJob::failedRecheckDue($at('3 hours', '61 minutes')))->toBeTrue()
        ->and(VerifyEdgeCustomDomainsJob::failedRecheckDue($at('2 days', '2 hours')))->toBeFalse()
        ->and(VerifyEdgeCustomDomainsJob::failedRecheckDue($at('2 days', '7 hours')))->toBeTrue()
        ->and(VerifyEdgeCustomDomainsJob::failedRecheckDue($at('4 days', '7 hours')))->toBeFalse()
        ->and(VerifyEdgeCustomDomainsJob::failedRecheckDue(['verified_at' => now()->toIso8601String()]))->toBeFalse();

    config(['edge.fake.enabled' => true, 'edge.custom_hostnames.enabled' => true]);
    $site = makeLiveEdgeSite();
    $meta = $site->edgeMeta();
    $meta['routing']['custom_domains']['late.example.com'] = ['hostname' => 'late.example.com', 'dns_status' => 'failed']
        + $at('2 hours', '2 hours');
    $meta['routing']['custom_domains']['stale.example.com'] = ['hostname' => 'stale.example.com', 'dns_status' => 'failed']
        + $at('5 days', '2 hours');
    $site->update(['meta' => array_merge($site->meta, ['edge' => $meta])]);

    (new VerifyEdgeCustomDomainsJob)->handle(provisionerWithDns([]));

    $domains = $site->fresh()->edgeMeta()['routing']['custom_domains'];
    expect(now()->diffInMinutes($domains['late.example.com']['verified_at'], true))->toBeLessThan(1)
        ->and(now()->diffInMinutes($domains['stale.example.com']['verified_at'], true))->toBeGreaterThan(100);
});

test('a repeat verification failure does not notify again', function () {
    config(['edge.fake.enabled' => true, 'edge.custom_hostnames.enabled' => true]);
    $site = makeLiveEdgeSite();
    $publisher = \Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->once();
    app()->instance(NotificationPublisher::class, $publisher);

    $provisioner = provisionerWithDns(['quiet.example.com' => [DNS_CNAME | DNS_A | DNS_AAAA => [['type' => 'CNAME', 'target' => 'elsewhere.example.net']]]]);
    $provisioner->provision($site->fresh(), 'quiet.example.com');
    $provisioner->verify($site->fresh(), 'quiet.example.com');
    $provisioner->verify($site->fresh(), 'quiet.example.com');
});

/** Attach and verify $hosts on $site via a CNAME to its own edge hostname. */
function readyDomains(Site $site, string ...$hosts): Site
{
    $dns = [];
    foreach ($hosts as $host) {
        $dns[$host] = [DNS_CNAME | DNS_A | DNS_AAAA => [['type' => 'CNAME', 'target' => 'edge-app.dply.host']]];
    }
    foreach ($hosts as $host) {
        provisionerWithDns($dns)->provision($site->fresh(), $host);
        expect(provisionerWithDns($dns)->verify($site->fresh(), $host)['dns_status'])->toBe('ready');
    }

    return $site->fresh();
}

test('the public url falls back to the dply hostname until the primary domain serves', function () {
    config(['edge.fake.enabled' => true, 'edge.custom_hostnames.enabled' => false]);
    $site = makeLiveEdgeSite();
    expect($site->edgePublicUrl())->toBe('https://edge-app.dply.host');

    $withDomain = function (array $entry, string $primary = 'www.example.com') use ($site): Site {
        $meta = $site->edgeMeta();
        $meta['routing']['primary_domain'] = $primary;
        $meta['routing']['custom_domains'] = ['www.example.com' => $entry];
        $site->meta = array_merge($site->meta, ['edge' => $meta]);

        return $site;
    };

    expect($withDomain(['dns_status' => 'pending'])->edgePublicUrl())->toBe('https://edge-app.dply.host')
        ->and($withDomain(['dns_status' => 'ready', 'ssl_status' => 'pending'])->edgePublicUrl())->toBe('https://edge-app.dply.host')
        ->and($withDomain(['dns_status' => 'ready', 'ssl_status' => 'active'])->edgePublicUrl())->toBe('https://www.example.com')
        ->and($withDomain(['dns_status' => 'ready'])->edgePublicUrl())->toBe('https://www.example.com')
        ->and($withDomain(['dns_status' => 'ready'], 'gone.example.com')->edgePublicUrl())->toBe('https://edge-app.dply.host');
});

test('the first ready domain becomes primary and later ones do not take over', function () {
    config(['edge.fake.enabled' => true, 'edge.custom_hostnames.enabled' => false]);
    $site = makeLiveEdgeSite();

    provisionerWithDns([])->provision($site->fresh(), 'pending.example.com');
    expect($site->fresh()->edgePrimaryDomain())->toBeNull();

    $site = readyDomains($site, 'www.example.com', 'shop.example.com');

    expect($site->edgePrimaryDomain())->toBe('www.example.com')
        ->and($site->edgePublicUrl())->toBe('https://www.example.com');
});

test('choosing the dply hostname sticks when a ready domain is re-verified', function () {
    config(['edge.fake.enabled' => true, 'edge.custom_hostnames.enabled' => false]);
    $site = readyDomains(makeLiveEdgeSite(), 'www.example.com');
    $user = $site->organization->users()->first();

    Livewire::actingAs($user)
        ->test(Routing::class, ['server' => $site->server, 'site' => $site])
        ->call('makeEdgeDomainPrimary', null);

    readyDomains($site, 'www.example.com');
    expect($site->fresh()->edgePublicUrl())->toBe('https://edge-app.dply.host');
});

test('make primary needs update rights and a ready domain', function () {
    config(['edge.fake.enabled' => true, 'edge.custom_hostnames.enabled' => false]);
    $site = readyDomains(makeLiveEdgeSite(), 'www.example.com', 'shop.example.com');
    provisionerWithDns([])->provision($site->fresh(), 'pending.example.com');
    $owner = $site->organization->users()->first();
    $viewer = User::factory()->create();
    $site->organization->users()->attach($viewer->id, ['role' => Organization::VIEW_ONLY_ROLE]);

    Livewire::actingAs($viewer)
        ->test(Routing::class, ['server' => $site->server, 'site' => $site->fresh()])
        ->assertOk()
        ->call('makeEdgeDomainPrimary', 'shop.example.com')
        ->assertForbidden();
    expect($site->fresh()->edgePrimaryDomain())->toBe('www.example.com');

    $component = Livewire::actingAs($owner)
        ->test(Routing::class, ['server' => $site->server, 'site' => $site->fresh()])
        ->call('makeEdgeDomainPrimary', 'pending.example.com');
    expect($site->fresh()->edgePrimaryDomain())->toBe('www.example.com');

    $component->call('makeEdgeDomainPrimary', 'shop.example.com')->assertSee('Primary');
    expect($site->fresh()->edgePublicUrl())->toBe('https://shop.example.com');
});

test('removing the primary domain hands it to the next ready domain, then back to dply', function () {
    config(['edge.fake.enabled' => true, 'edge.custom_hostnames.enabled' => false]);
    $site = readyDomains(makeLiveEdgeSite(), 'www.example.com', 'shop.example.com');
    $provisioner = app(EdgeCustomDomainProvisioner::class);

    $provisioner->remove($site->fresh(), 'www.example.com');
    expect($site->fresh()->edgePrimaryDomain())->toBe('shop.example.com');

    $provisioner->remove($site->fresh(), 'shop.example.com');
    $site->refresh();
    expect($site->edgePrimaryDomain())->toBeNull()
        ->and($site->edgeMeta()['routing'])->not->toHaveKey('primary_domain')
        ->and($site->edgePublicUrl())->toBe('https://edge-app.dply.host');
});
