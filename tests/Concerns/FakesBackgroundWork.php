<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Keeps feature tests from running queued work inline, and cleans up the
 * database connection afterwards.
 *
 * Replaces the old FakesRemoteServerAccess, which also faked SSH and the
 * TaskRunner — both left with the VM platform in the Edge cut. Edge builds and
 * publishes are ordinary queued jobs, so faking the queue is all that is left
 * to do here.
 *
 * Applied to every test under tests/Feature via tests/Pest.php.
 */
trait FakesBackgroundWork
{
    protected function setUpFakesBackgroundWork(): void
    {
        Queue::fake();

        // After RefreshDatabase rolls back, drop the connection so a killed run
        // does not leave idle-in-transaction sessions blocking a later migrate.
        $this->beforeApplicationDestroyed(function (): void {
            try {
                DB::disconnect(config('database.default'));
            } catch (\Throwable) {
                // Best-effort cleanup only.
            }
        });
    }
}
