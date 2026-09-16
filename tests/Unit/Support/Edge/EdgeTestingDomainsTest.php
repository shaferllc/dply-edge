<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Edge;

use App\Modules\Edge\Support\EdgeTestingDomains;

test('default apex prefers on-dply.live when present', function () {
    config(['edge.testing_domains' => ['dply.host', 'on-dply.live', 'on-dply.cloud']]);

    expect(EdgeTestingDomains::defaultApex())->toBe('on-dply.live');
});

test('default apex falls back to on-dply.cloud when site absent', function () {
    config(['edge.testing_domains' => ['dply.host', 'on-dply.cloud', 'on-dply.app']]);

    expect(EdgeTestingDomains::defaultApex())->toBe('on-dply.cloud');
});

test('default apex uses first on-dply domain when site and cloud absent', function () {
    config(['edge.testing_domains' => ['dply.host', 'on-dply.app']]);

    expect(EdgeTestingDomains::defaultApex())->toBe('on-dply.app');
});

test('zone for host matches configured edge testing domains', function () {
    config(['edge.testing_domains' => ['on-dply.site']]);

    expect(EdgeTestingDomains::zoneForHost('my-app-abc123.on-dply.site'))->toBe('on-dply.site');
    expect(EdgeTestingDomains::zoneForHost('unknown.example.test'))->toBeNull();
});

test('default from pool filters on-dply domains from config pool when env unset', function () {
    config([
        'services.digitalocean.testing_domains' => ['dply.host', 'on-dply.live', 'on-dply.cloud'],
    ]);

    $pool = EdgeTestingDomains::defaultFromPool();

    expect($pool)->toContain('on-dply.live')
        ->and($pool[0])->toBe('on-dply.live')
        ->and($pool)->not->toContain('dply.host');
});

test('analytics zone resolves worker route and shared testing pool hostnames', function () {
    config([
        'edge.testing_domains' => ['on-dply.site'],
        'edge.cloudflare.worker_zone_name' => 'on-dply.site',
        'edge.cloudflare.worker_routes' => ['*.on-dply.site/*', '*.dply.host/*'],
        'services.digitalocean.testing_domains' => ['dply.host', 'on-dply.site'],
    ]);

    expect(EdgeTestingDomains::analyticsZoneForHost('demo.on-dply.site'))->toBe('on-dply.site');
    expect(EdgeTestingDomains::analyticsZoneForHost('demo.dply.host'))->toBe('dply.host');
});
