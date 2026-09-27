<?php

namespace Tests\Unit\EdgeArtifactPublisherTest;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeArtifactPublisher;
use App\Modules\Edge\Services\EdgeBuildCache;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;

/**
 * Drives the real S3 client behind an `s3` disk with a recording handler, so
 * the exact commands (and their params) the publisher sends are asserted.
 *
 * @param  array<string, string>  $listing  key => ETag returned by ListObjectsV2
 * @param  list<string>  $failKeys  keys whose Put/Copy is rejected
 * @param  list<string>  $failCommands  command names to reject for those keys
 */
function fakeS3(array $listing = [], array $failKeys = [], array $failCommands = ['PutObject', 'CopyObject']): \ArrayObject
{
    config(['filesystems.disks.edge_test_s3' => [
        'driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'auto',
        'bucket' => 'bkt', 'endpoint' => 'http://127.0.0.1:1', 'use_path_style_endpoint' => true,
        'throw' => false, 'report' => false,
    ]]);
    Storage::forgetDisk('edge_test_s3');

    $log = new \ArrayObject;
    Storage::disk('edge_test_s3')->getClient()->getHandlerList()->setHandler(
        function (CommandInterface $cmd, $request) use ($listing, $failKeys, $failCommands, $log) {
            $params = $cmd->toArray();
            $log[] = ['name' => $cmd->getName(), 'params' => $params];
            if ($cmd->getName() === 'ListObjectsV2') {
                $contents = [];
                foreach ($listing as $key => $etag) {
                    if (str_starts_with($key, (string) $params['Prefix'])) {
                        $contents[] = ['Key' => $key, 'ETag' => $etag];
                    }
                }

                return Create::promiseFor(new Result(['Contents' => $contents, 'IsTruncated' => false]));
            }
            if (in_array($params['Key'] ?? null, $failKeys, true) && in_array($cmd->getName(), $failCommands, true)) {
                return Create::rejectionFor(new AwsException('boom', $cmd, ['code' => 'InternalError']));
            }

            return Create::promiseFor(new Result([]));
        },
    );

    return $log;
}

function artifactDir(int $files = 3): string
{
    $dir = sys_get_temp_dir().'/dply-edge-publisher-test-'.uniqid();
    File::ensureDirectoryExists($dir.'/assets');
    file_put_contents($dir.'/index.html', '<h1>hi</h1>');
    file_put_contents($dir.'/assets/app.abcdef12.js', 'console.log(1)');
    for ($i = 3; $i <= $files; $i++) {
        file_put_contents($dir."/assets/f{$i}.css", "body{order:{$i}}");
    }

    return $dir;
}

test('uploads every file with the same headers as before, streamed', function () {
    $log = fakeS3();
    $dir = artifactDir();

    expect(app(EdgeArtifactPublisher::class)->uploadDirectory($dir, 'edge/o/s/new', 'edge_test_s3'))->toBe(3);

    $puts = collect($log)->where('name', 'PutObject')->keyBy(fn ($c) => $c['params']['Key']);
    expect($puts)->toHaveCount(3)
        ->and($puts['edge/o/s/new/index.html']['params'])->toMatchArray([
            'Bucket' => 'bkt', 'ACL' => 'public-read',
            'CacheControl' => 'public, max-age=0, must-revalidate',
            'ContentType' => 'text/html; charset=utf-8',
        ])
        ->and($puts['edge/o/s/new/assets/app.abcdef12.js']['params'])->toMatchArray([
            'CacheControl' => 'public, max-age=31536000, immutable',
            'ContentType' => 'application/javascript; charset=utf-8',
        ])
        ->and($puts['edge/o/s/new/index.html']['params']['Body'])->toBeInstanceOf(StreamInterface::class);

    File::deleteDirectory($dir);
});

test('unchanged files are copied server-side from the previous deployment', function () {
    $dir = artifactDir();
    $log = fakeS3([
        'edge/o/s/old/index.html' => '"'.md5_file($dir.'/index.html').'"',            // same → copy
        'edge/o/s/old/assets/app.abcdef12.js' => '"'.md5('something else').'"',     // changed → put
        'edge/o/s/old/assets/f3.css' => '"'.md5_file($dir.'/assets/f3.css').'-2"',  // multipart → put
    ]);

    app(EdgeArtifactPublisher::class)->uploadDirectory($dir, 'edge/o/s/new', 'edge_test_s3', 'edge/o/s/old');

    $copies = collect($log)->where('name', 'CopyObject')->values();
    expect($copies)->toHaveCount(1)
        ->and($copies[0]['params'])->toMatchArray([
            'Key' => 'edge/o/s/new/index.html',
            'CopySource' => 'bkt/edge/o/s/old/index.html',
            'MetadataDirective' => 'REPLACE',
            'ACL' => 'public-read',
            'CacheControl' => 'public, max-age=0, must-revalidate',
            'ContentType' => 'text/html; charset=utf-8',
        ])
        ->and(collect($log)->where('name', 'PutObject')->pluck('params.Key')->sort()->values()->all())
        ->toBe(['edge/o/s/new/assets/app.abcdef12.js', 'edge/o/s/new/assets/f3.css']);

    File::deleteDirectory($dir);
});

test('a failed reuse copy falls back to an upload', function () {
    $dir = artifactDir();
    $log = fakeS3(
        ['edge/o/s/old/index.html' => '"'.md5_file($dir.'/index.html').'"'],
        ['edge/o/s/new/index.html'],
        ['CopyObject'],
    );

    expect(app(EdgeArtifactPublisher::class)->uploadDirectory($dir, 'edge/o/s/new', 'edge_test_s3', 'edge/o/s/old'))->toBe(3);
    expect(collect($log)->where('params.Key', 'edge/o/s/new/index.html')->pluck('name')->all())
        ->toBe(['CopyObject', 'PutObject']);

    File::deleteDirectory($dir);
});

test('any failed upload fails the whole publish', function () {
    $dir = artifactDir(40);
    fakeS3([], ['edge/o/s/new/assets/f17.css']);

    expect(fn () => app(EdgeArtifactPublisher::class)->uploadDirectory($dir, 'edge/o/s/new', 'edge_test_s3'))
        ->toThrow(\RuntimeException::class, 'Failed to upload 1 of 40 artifact(s) to R2 (first: assets/f17.css: InternalError)');

    File::deleteDirectory($dir);
});

test('the local-disk path throws when a write fails', function () {
    $dir = artifactDir();
    $disk = \Mockery::mock(Filesystem::class);
    $disk->shouldReceive('writeStream')->andReturn(true, false);
    Storage::shouldReceive('disk')->andReturn($disk);

    expect(fn () => app(EdgeArtifactPublisher::class)->uploadDirectory($dir, 'p', 'x'))
        ->toThrow(\RuntimeException::class, 'Failed to upload artifact');

    File::deleteDirectory($dir);
});

test('promote copies concurrently, keeps the build log private, and fails loudly', function () {
    $log = fakeS3([], ['edge/o/s/new/b.js']);
    $disk = \Mockery::mock(Storage::disk('edge_test_s3'))->makePartial();
    $disk->shouldReceive('allFiles')->with('edge/o/s/old')->andReturn([
        'edge/o/s/old/a.html', 'edge/o/s/old/b.js', 'edge/o/s/old/build.log',
    ]);
    Storage::set('edge_test_s3', $disk);

    expect(fn () => app(EdgeArtifactPublisher::class)->copyPrefix('edge/o/s/old', 'edge/o/s/new', 'edge_test_s3'))
        ->toThrow(\RuntimeException::class, 'Failed to copy 1 of 3 artifact(s)');

    $copies = collect($log)->where('name', 'CopyObject')->keyBy('params.Key');
    expect($copies)->toHaveCount(3)
        ->and($copies['edge/o/s/new/a.html']['params'])->toMatchArray([
            'CopySource' => 'bkt/edge/o/s/old/a.html', 'MetadataDirective' => 'COPY', 'ACL' => 'public-read',
        ])
        ->and($copies['edge/o/s/new/build.log']['params']['ACL'])->toBe('private');
});

test('build cache falls back to the newest same-env cache on a lockfile miss, streamed', function () {
    config(['edge.fake.enabled' => false]);
    Storage::fake('edge_cache_test');
    $cache = app(EdgeBuildCache::class);
    $site = new Site;
    $site->id = '01TESTSITE0000000000000000';

    $checkout = sys_get_temp_dir().'/dply-edge-cache-test-'.uniqid();
    File::ensureDirectoryExists($checkout.'/node_modules/left-pad');
    file_put_contents($checkout.'/node_modules/left-pad/index.js', 'module.exports=1');
    file_put_contents($checkout.'/package-lock.json', '{"v":1}');
    $oldKey = $cache->cacheKey($checkout, null);
    expect($cache->snapshot($checkout, null, $oldKey, $site, 'edge_cache_test')['ok'])->toBeTrue();

    // Other env (node 22) must never be picked as a fallback.
    Storage::disk('edge_cache_test')->put('cache/'.$site->id.'/'.$cache->cacheKey($checkout, null, '22').'.tar.gz', 'x');
    touch(Storage::disk('edge_cache_test')->path('cache/'.$site->id.'/'.$cache->cacheKey($checkout, null, '22').'.tar.gz'), time() + 60);

    File::deleteDirectory($checkout.'/node_modules');
    file_put_contents($checkout.'/package-lock.json', '{"v":2}');
    $newKey = $cache->cacheKey($checkout, null);
    expect($newKey)->not->toBe($oldKey)
        ->and(strstr($newKey, '-', true))->toBe(strstr($oldKey, '-', true));

    $result = $cache->restore($checkout, null, $newKey, $site, 'edge_cache_test');
    expect($result['ok'])->toBeTrue()
        ->and($result['message'])->toContain('restored fallback '.$oldKey)
        ->and(file_exists($checkout.'/node_modules/left-pad/index.js'))->toBeTrue();

    // Another site sees nothing.
    $other = new Site;
    $other->id = '01OTHERSITE000000000000000';
    expect($cache->restore($checkout, null, $newKey, $other, 'edge_cache_test')['ok'])->toBeFalse();

    File::deleteDirectory($checkout);
});
