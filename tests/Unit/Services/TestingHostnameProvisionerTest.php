<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TestingHostnameProvisionerTest;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SitePreviewDomain;
use App\Services\Sites\TestingHostnameProvisioner;
use Illuminate\Support\Collection;

test('vm testing hostnames prefer cloudflare when a platform token exists', function () {
    config([
        'testing_domains.provider' => 'cloudflare',
        'testing_domains.vm_apex' => 'on-dply.cc',
        'testing_domains.vm' => ['on-dply.cc', 'dply.host'],
        'testing_domains.cloudflare_api_token' => 'cf-dns-token',
        'services.namecheap.api_user' => 'tshafer',
        'services.namecheap.api_key' => 'nc-key',
        'services.namecheap.client_ip' => '1.2.3.4',
        'services.digitalocean.token' => 'do-token',
    ]);

    $site = new Site([
        'name' => 'Marketing API',
        'slug' => 'marketing-api',
    ]);

    $routing = app(TestingHostnameProvisioner::class)->testingDnsRoutingForSite($site);

    expect($routing['provider'])->toBe('cloudflare');
    expect($routing['token'])->toBe('cf-dns-token');
    expect($routing['credential'])->toBeNull();
});

test('it chooses a domain from the owned pool deterministically', function () {
    config([
        'services.digitalocean.testing_domains' => ['dply.cc', 'dply.host', 'dply.io'],
        'services.digitalocean.testing_domain_strategy' => 'deterministic',
    ]);

    $site = new Site([
        'name' => 'Marketing API',
        'slug' => 'marketing-api',
    ]);
    $site->id = '01jtestsite0000000000000000';

    $provisioner = app(TestingHostnameProvisioner::class);
    $first = $provisioner->chooseZone($site);
    $second = $provisioner->chooseZone($site);

    expect(['dply.cc', 'dply.host', 'dply.io'])->toContain($first);
    expect($second)->toBe($first);
});
test('it builds a testing hostname from site slug and zone', function () {
    $site = new Site([
        'name' => 'Marketing API',
        'slug' => 'marketing-api',
    ]);
    $site->id = '01jtestsite0000000000000000';

    $hostname = app(TestingHostnameProvisioner::class)->buildHostname($site, 'dply.cc');

    expect($hostname)->toEndWith('.dply.cc');
    $this->assertStringContainsString('marketing-api', $hostname);
});
test('ssl hostnames prefer the testing hostname when present', function () {
    $site = new Site([
        'meta' => [
            'testing_hostname' => [
                'status' => 'ready',
                'hostname' => 'preview-app.dply.cc',
            ],
        ],
    ]);

    $site->setRelation('domains', new Collection([
        new SiteDomain(['hostname' => 'app.example.com', 'is_primary' => true]),
        new SiteDomain(['hostname' => 'preview-app.dply.cc', 'is_primary' => false]),
    ]));

    expect($site->sslDomainHostnames()->all())->toBe(['preview-app.dply.cc']);
});
test('vm testing hostnames fall back to digitalocean without cloudflare or namecheap credentials', function () {
    config([
        'testing_domains.vm' => ['dply.host'],
        'testing_domains.cloudflare_api_token' => '',
        'services.cloudflare.key' => '',
        'edge.cloudflare.api_token' => '',
        'serverless.testing_dns.cloudflare_api_token' => '',
        'services.namecheap.api_user' => '',
        'services.namecheap.api_key' => '',
        'services.namecheap.client_ip' => '',
        'services.digitalocean.token' => 'do_token',
        'services.digitalocean.testing_domains' => ['dply.host'],
    ]);

    $site = new Site([
        'name' => 'Marketing API',
        'slug' => 'marketing-api',
    ]);

    $routing = app(TestingHostnameProvisioner::class)->testingDnsRoutingForSite($site);

    expect($routing['provider'])->toBe('digitalocean');
    expect($routing['token'])->toBe('do_token');
});

test('testing hostname prefers primary preview domain over legacy meta', function () {
    $site = new Site([
        'meta' => [
            'testing_hostname' => [
                'status' => 'ready',
                'hostname' => 'legacy-preview.dply.cc',
            ],
        ],
    ]);

    $site->setRelation('previewDomains', new Collection([
        new SitePreviewDomain([
            'hostname' => 'preview-app.dply.cc',
            'dns_status' => 'ready',
            'is_primary' => true,
        ]),
    ]));

    expect($site->testingHostname())->toBe('preview-app.dply.cc');
    expect($site->testingHostnameStatus())->toBe('ready');
});

test('testing-domain DNS prefers the DNS token over the mail binding\'s CLOUDFLARE_KEY', function () {
    putenv('DPLY_TESTING_CF_API_TOKEN');
    putenv('CLOUDFLARE_KEY=email-sending-token');
    putenv('CLOUDFLARE_API_KEY=dns-token');

    try {
        $config = require base_path('config/product/testing_domains.php');
    } finally {
        putenv('CLOUDFLARE_KEY');
        putenv('CLOUDFLARE_API_KEY');
    }

    expect($config['cloudflare_api_token'])->toBe('dns-token');
});
