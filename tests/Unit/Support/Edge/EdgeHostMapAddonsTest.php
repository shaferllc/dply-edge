<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Edge;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeHostMapAddons;

test('managed edge site addons flatten into host map fields', function () {
    config([
        'edge.log_ingest.key' => 'test-ingest-key',
        'edge.log_ingest.base_url' => 'https://app.example.test',
    ]);

    $site = new Site([
        'edge_backend' => 'dply_edge',
        'meta' => [
            'edge' => [
                'turnstile' => [
                    'enabled' => true,
                    'site_key' => 'site',
                    'secret_key' => 'secret',
                    'mode' => 'forms',
                ],
                'rate_limit' => [
                    'enabled' => true,
                    'rules' => [
                        ['path' => '/api/*', 'limit' => 30, 'window_seconds' => 60, 'action' => 'challenge'],
                    ],
                ],
                'forms' => [
                    'enabled' => true,
                    'endpoints' => [
                        ['path' => '/contact', 'to_email' => 'ops@example.com', 'honeypot' => 'company', 'require_turnstile' => true],
                    ],
                ],
                'waiting_room' => [
                    'enabled' => true,
                    'total_active_users' => 50,
                    'new_users_per_minute' => 5,
                    'session_duration_minutes' => 15,
                    'paths' => ['/*'],
                ],
                'snippets' => [
                    'enabled' => true,
                    'items' => [
                        ['name' => 'pixel', 'phase' => 'body', 'path' => '/*', 'html' => '<!-- x -->'],
                    ],
                ],
                'tags' => [
                    'enabled' => true,
                    'consent_required' => true,
                    'tools' => [
                        ['name' => 'ga', 'src' => 'https://www.googletagmanager.com/gtag/js', 'async' => true],
                    ],
                ],
                'jobs' => [
                    'enabled' => true,
                    'default_queue' => 'JOBS',
                ],
            ],
        ],
    ]);
    $site->id = '11111111-1111-1111-1111-111111111111';

    $payload = EdgeHostMapAddons::payload($site);

    expect($payload['turnstile']['site_key'])->toBe('site')
        ->and($payload['rate_limit']['rules'][0]['action'])->toBe('challenge')
        ->and($payload['forms']['endpoints'][0]['path'])->toBe('/contact')
        ->and($payload['waiting_room']['total_active_users'])->toBe(50)
        ->and($payload['snippets']['items'][0]['html'])->toBe('<!-- x -->')
        ->and($payload['tags']['tools'][0]['src'])->toStartWith('https://')
        ->and($payload['jobs']['default_queue'])->toBe('JOBS');
});

test('tags consent helper publishes without script urls', function () {
    $site = new Site([
        'edge_backend' => 'dply_edge',
        'meta' => [
            'edge' => [
                'tags' => [
                    'enabled' => true,
                    'consent_required' => true,
                    'tools' => [
                        ['name' => 'analytics', 'src' => '', 'async' => true],
                    ],
                ],
            ],
        ],
    ]);
    $site->id = '22222222-2222-2222-2222-222222222222';

    $payload = EdgeHostMapAddons::payload($site);

    expect($payload['tags'] ?? null)->toMatchArray([
        'enabled' => true,
        'consent_required' => true,
        'tools' => [],
    ]);
});

test('byo cloudflare sites do not receive addon host map fields', function () {
    $site = new Site([
        'edge_backend' => 'org_cloudflare',
        'meta' => [
            'edge' => [
                'turnstile' => ['enabled' => true, 'site_key' => 'x', 'secret_key' => 'y', 'mode' => 'all'],
            ],
        ],
    ]);

    expect(EdgeHostMapAddons::payload($site))->toBe([]);
});

test('repo tags snippets and forms publish when dashboard has not saved them', function () {
    config([
        'edge.log_ingest.key' => 'test-ingest-key',
        'edge.log_ingest.base_url' => 'https://app.example.test',
    ]);

    $site = new Site([
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => []],
    ]);
    $site->id = '33333333-3333-3333-3333-333333333333';

    $deployment = new EdgeDeployment;
    $deployment->repo_config = [
        'tags' => [
            'enabled' => true,
            'consent_required' => false,
            'tools' => [
                ['name' => 'ga', 'src' => 'https://www.googletagmanager.com/gtag/js?id=G-REPO', 'async' => true],
            ],
        ],
        'snippets' => [
            'enabled' => true,
            'items' => [
                ['name' => 'meta', 'phase' => 'head', 'path' => '/*', 'html' => '<!-- repo -->'],
            ],
        ],
        'forms' => [
            'enabled' => true,
            'endpoints' => [
                ['path' => '/contact', 'to_email' => 'repo@example.com', 'honeypot' => 'company', 'require_turnstile' => true],
            ],
        ],
    ];

    $payload = EdgeHostMapAddons::payload($site, $deployment);

    expect($payload['tags']['tools'][0]['src'])->toContain('G-REPO')
        ->and($payload['snippets']['items'][0]['html'])->toBe('<!-- repo -->')
        ->and($payload['forms']['endpoints'][0]['to_email'])->toBe('repo@example.com');
});

test('dashboard tags override repo tags when edgeMeta has the section', function () {
    $site = new Site([
        'edge_backend' => 'dply_edge',
        'meta' => [
            'edge' => [
                'tags' => [
                    'enabled' => true,
                    'consent_required' => false,
                    'tools' => [
                        ['name' => 'dash', 'src' => 'https://example.com/dash.js', 'async' => true],
                    ],
                ],
            ],
        ],
    ]);
    $site->id = '44444444-4444-4444-4444-444444444444';

    $deployment = new EdgeDeployment;
    $deployment->repo_config = [
        'tags' => [
            'enabled' => true,
            'tools' => [
                ['name' => 'repo', 'src' => 'https://example.com/repo.js', 'async' => true],
            ],
        ],
    ];

    $payload = EdgeHostMapAddons::payload($site, $deployment);

    expect($payload['tags']['tools'][0]['src'])->toBe('https://example.com/dash.js');
});

test('vendor tags publish valid ids and drop malformed ones', function () {
    $site = new Site([
        'edge_backend' => 'dply_edge',
        'meta' => [
            'edge' => [
                'tags' => [
                    'enabled' => true,
                    'tools' => [
                        ['vendor' => 'ga4', 'id' => 'g-abc123', 'path' => '/blog/*'],
                        ['vendor' => 'ga4', 'id' => "G-1');alert(1)//"],
                        ['vendor' => 'meta', 'id' => 'not-digits'],
                        ['vendor' => 'nope', 'id' => '123456'],
                    ],
                ],
            ],
        ],
    ]);
    $site->id = '55555555-5555-5555-5555-555555555555';

    $tools = EdgeHostMapAddons::payload($site)['tags']['tools'];

    expect($tools)->toHaveCount(1)
        ->and($tools[0])->toMatchArray(['vendor' => 'ga4', 'id' => 'G-ABC123', 'src' => '', 'purpose' => 'analytics', 'path' => '/blog/*']);
});
