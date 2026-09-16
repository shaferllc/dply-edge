<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Edge;

use App\Modules\Edge\Support\EdgeLiveBuildLog;

beforeEach(function () {
    EdgeLiveBuildLog::clear('test-deploy-1');
});

afterEach(function () {
    EdgeLiveBuildLog::clear('test-deploy-1');
});

test('live build log appends and reads by offset via redis', function () {
    EdgeLiveBuildLog::append('test-deploy-1', "line one\n");
    EdgeLiveBuildLog::append('test-deploy-1', "line two\n");

    $first = EdgeLiveBuildLog::readSince('test-deploy-1', 0, 10_000);
    expect($first['exists'])->toBeTrue()
        ->and($first['body'])->toBe("line one\nline two\n");

    $second = EdgeLiveBuildLog::readSince('test-deploy-1', $first['offset'], 10_000);
    expect($second['body'])->toBe('')
        ->and($second['exists'])->toBeTrue();

    EdgeLiveBuildLog::append('test-deploy-1', "line three\n");
    $third = EdgeLiveBuildLog::readSince('test-deploy-1', $first['offset'], 10_000);
    expect($third['body'])->toBe("line three\n");
});

test('missing deployment log reports not exists', function () {
    $chunk = EdgeLiveBuildLog::readSince('never-written-deploy', 0);

    expect($chunk['exists'])->toBeFalse()
        ->and($chunk['body'])->toBe('');
});
