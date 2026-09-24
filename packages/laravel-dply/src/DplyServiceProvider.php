<?php

declare(strict_types=1);

namespace Dply\Laravel;

use Illuminate\Cache\CacheManager;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

class DplyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app['config']->has('queue.connections.dply')) {
            $this->app['config']->set('queue.connections.dply', [
                'driver' => 'dply',
                'queue' => env('DPLY_QUEUE', 'JOBS'),
                'app_url' => env('DPLY_APP_URL'),
                'token' => env('DPLY_QUEUE_TOKEN'),
            ]);
        }

        $this->registerStorageDisks();
        $this->registerKvStores();
    }

    public function boot(): void
    {
        /** @var QueueManager $manager */
        $manager = $this->app['queue'];
        $manager->addConnector('dply', fn () => new DplyConnector);
        /** @var CacheManager $cache */
        $cache = $this->app['cache'];
        $cache->extend('dply', function ($app, array $config) {
            return $app['cache']->repository(new DplyKvStore((string) ($config['host'] ?? '')));
        });
        Storage::extend('dply', function ($app, array $config) {
            $adapter = new DplyAdapter((string) ($config['host'] ?? ''));

            return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });

        // No web middleware: the Worker authenticates with DPLY_QUEUE_TOKEN.
        Route::post('/_dply/queue', QueueController::class)->name('dply.queue');
        Route::post('/_dply/schedule', ScheduleController::class)->name('dply.schedule');
    }

    /**
     * One disk per attached bucket. The first is also the s3 disk when the
     * app has no S3 key of its own, so Storage::disk('s3') keeps working.
     */
    private function registerStorageDisks(): void
    {
        $disks = [];
        foreach (explode(',', (string) env('DPLY_STORAGE_DISKS', '')) as $pair) {
            [$name, $host] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($name !== '' && $host !== '') {
                $disks[$name] = $host;
            }
        }
        $host = (string) env('DPLY_STORAGE_HOST', '');
        $primary = (string) env('DPLY_STORAGE_DISK', '');
        if ($host !== '' && $primary !== '' && ! isset($disks[$primary])) {
            $disks[$primary] = $host;
        }
        foreach ($disks as $name => $diskHost) {
            $this->app['config']->set('filesystems.disks.'.$name, [
                'driver' => 'dply',
                'host' => $diskHost,
            ]);
        }
        if ($host !== '' && (string) env('AWS_ACCESS_KEY_ID', '') === '') {
            $this->app['config']->set('filesystems.disks.s3', [
                'driver' => 'dply',
                'host' => $host,
            ]);
        }
        if ($primary !== '' && env('FILESYSTEM_DISK') === null) {
            $this->app['config']->set('filesystems.default', $primary);
        }
    }

    /**
     * One cache store per attached key-value store. CACHE_STORE names the
     * default when Redis is not attached.
     */
    private function registerKvStores(): void
    {
        $stores = [];
        foreach (explode(',', (string) env('DPLY_KV_STORES', '')) as $pair) {
            [$name, $host] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($name !== '' && $host !== '') {
                $stores[$name] = $host;
            }
        }
        $host = (string) env('DPLY_KV_HOST', '');
        $primary = (string) env('DPLY_KV_STORE', '');
        if ($host !== '' && $primary !== '' && ! isset($stores[$primary])) {
            $stores[$primary] = $host;
        }
        foreach ($stores as $name => $storeHost) {
            $this->app['config']->set('cache.stores.'.$name, [
                'driver' => 'dply',
                'host' => $storeHost,
            ]);
        }
    }
}
