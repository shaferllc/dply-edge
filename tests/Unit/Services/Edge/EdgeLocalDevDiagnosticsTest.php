<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge;

use App\Modules\Edge\Support\EdgeLocalDevDiagnostics;

test('fake mode banner hints valet test domain', function () {
    config([
        'app.url' => 'https://dplyi.test',
        'edge.testing_domains' => ['edge.test'],
    ]);

    $hint = EdgeLocalDevDiagnostics::fakeModeBannerHint();

    expect($hint['message'])->toContain('edge.test');
    expect($hint['message'])->toContain('docs/edge-local-development.md');
});

test('fake mode banner suggests app url host when testing domain is not valet', function () {
    config([
        'app.url' => 'https://dplyi.test',
        'edge.testing_domains' => ['dply.host'],
    ]);

    $hint = EdgeLocalDevDiagnostics::fakeModeBannerHint();

    expect($hint['message'])->toContain('dplyi.test');
    expect($hint['message'])->toContain('dply.host');
});

test('local dev checks fail when testing domain missing', function () {
    // EdgeTestingDomains::all() falls back to the shared testing_domains
    // config, so both sources have to be empty for "no domain configured".
    config([
        'edge.testing_domains' => [],
        'testing_domains.edge' => [],
        'testing_domains.edge_apex' => '',
    ]);

    $checks = EdgeLocalDevDiagnostics::checks();

    expect($checks[0]['ok'] ?? true)->toBeFalse();
    expect($checks[0]['name'] ?? '')->toBe('edge_testing_domain');
});
