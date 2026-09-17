<?php

declare(strict_types=1);

namespace Dply\Laravel;

use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

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
    }

    public function boot(): void
    {
        /** @var QueueManager $manager */
        $manager = $this->app['queue'];
        $manager->addConnector('dply', fn () => new DplyConnector);

        // No web middleware: the Worker authenticates with DPLY_QUEUE_TOKEN.
        Route::post('/_dply/queue', QueueController::class)->name('dply.queue');
        Route::post('/_dply/schedule', ScheduleController::class)->name('dply.schedule');
    }
}
