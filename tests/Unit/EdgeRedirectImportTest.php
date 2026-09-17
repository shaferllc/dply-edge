<?php

declare(strict_types=1);

namespace Tests\Unit\EdgeRedirectImportTest;

use App\Modules\Edge\Services\Config\EdgeRepoConfigLoader;
use App\Modules\Edge\Support\EdgeRedirectImport;

test('parses cloudflare bulk redirect csv', function () {
    $r = EdgeRedirectImport::parse("example.com/blog/,https://example.com/blog/latest,301\nexample.net/,https://example.net/soon.html,307\n");

    expect($r['format'])->toBe('csv');
    expect($r['errors'])->toBe([]);
    expect($r['redirects'])->toBe([
        ['from' => '/blog/', 'to' => 'https://example.com/blog/latest', 'status' => 301],
        ['from' => '/', 'to' => 'https://example.net/soon.html', 'status' => 307],
    ]);
});

test('parses netlify _redirects whitespace format', function () {
    $r = EdgeRedirectImport::parse("/old-page   /new-page   301\n/blog/*  /news/:splat  301\n");

    expect($r['format'])->toBe('list');
    expect($r['redirects'])->toBe([
        ['from' => '/old-page', 'to' => '/new-page', 'status' => 301],
        ['from' => '/blog/*', 'to' => '/news/:splat', 'status' => 301],
    ]);
});

test('strips scheme and host, keeps bare host as root', function () {
    $r = EdgeRedirectImport::parse("https://example.com/a,/b\nhttp://example.com/c,/d\nexample.com,/e\n");

    expect(array_column($r['redirects'], 'from'))->toBe(['/a', '/c', '/']);
});

test('skips blanks, comments and a csv header row', function () {
    $r = EdgeRedirectImport::parse("source_url,target_url,status_code\n\n# a comment\n/a,/b,301\n");

    expect($r['redirects'])->toBe([['from' => '/a', 'to' => '/b', 'status' => 301]]);
    expect($r['errors'])->toBe([]);
});

test('defaults a missing status to 301', function () {
    $r = EdgeRedirectImport::parse('/a /b');

    expect($r['redirects'])->toBe([['from' => '/a', 'to' => '/b', 'status' => 301]]);
});

test('reports bad statuses and short lines without dropping good rules', function () {
    // 200 is not a redirect; 399 is 3xx but outside the set dply.yaml accepts.
    $r = EdgeRedirectImport::parse("/a,/b,200\n/lonely\n/c,/d,399\n/e,/f,302\n");

    expect($r['redirects'])->toBe([['from' => '/e', 'to' => '/f', 'status' => 302]]);
    expect($r['errors'])->toHaveCount(3);
    expect($r['errors'][0])->toContain('Line 1');
    expect($r['errors'][1])->toContain('Line 2');
    expect($r['errors'][2])->toContain('Line 3');
});

test('accepts every status dply.yaml allows', function () {
    foreach (EdgeRepoConfigLoader::ALLOWED_STATUS_CODES as $status) {
        $r = EdgeRedirectImport::parse("/a,/b,{$status}");

        expect($r['errors'])->toBe([]);
        expect($r['redirects'][0]['status'])->toBe($status);
    }
});

test('caps an oversized paste and says so', function () {
    $raw = str_repeat("/a,/b,301\n", EdgeRedirectImport::MAX_RULES + 5);
    $r = EdgeRedirectImport::parse($raw);

    expect($r['redirects'])->toHaveCount(EdgeRedirectImport::MAX_RULES);
    expect($r['errors'][0])->toContain((string) EdgeRedirectImport::MAX_RULES);
});

test('empty input yields no format', function () {
    $r = EdgeRedirectImport::parse("\n  \n# just a comment\n");

    expect($r['format'])->toBeNull();
    expect($r['redirects'])->toBe([]);
    expect($r['errors'])->toBe([]);
});
