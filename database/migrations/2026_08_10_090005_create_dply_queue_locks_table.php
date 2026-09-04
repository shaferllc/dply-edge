<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared atomic locks for a dply Queue namespace.
 *
 * These exist because fixing the queue alone was not enough. Three of
 * Laravel's most-used queue features — `ShouldBeUnique`,
 * `WithoutOverlapping`, and `RateLimited` — are backed by the **cache**, not
 * the queue. On a function the cache store defaults to `array`, which is
 * per-invocation, so all three silently do nothing: the lock is always
 * acquired, every duplicate runs, and nothing errors or logs. That is a worse
 * failure than the queue one it sits behind, because it looks like it works.
 *
 * A lock is a row here instead, reachable from every concurrent invocation.
 *
 * Lives on the `dply_queue` connection with the job rows: same tenancy key,
 * same churn profile, same reason to stay out of the control-plane database.
 *
 * Deliberately NOT a general-purpose cache. This backs `LockProvider` only —
 * the atomic compare-and-set that the three features above need. A full
 * managed cache is a different product with different economics.
 */
return new class extends Migration
{
    protected $connection = 'dply_queue';

    public function up(): void
    {
        if (Schema::connection('dply_queue')->hasTable('dply_queue_locks')) {
            return;
        }

        Schema::connection('dply_queue')->create('dply_queue_locks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('namespace_id');

            // Laravel's lock name, e.g. `laravel_unique_job:App\Jobs\Sync:42`.
            $table->string('name', 255);

            /**
             * The holder's token. Release matches on it, so a process whose
             * lock already expired cannot release the lock a different holder
             * has since taken — the same fencing rule the job reservations
             * use, for the same reason.
             */
            $table->string('owner', 100);

            $table->timestampTz('expires_at', 6);
            $table->timestampTz('created_at', 6)->nullable();

            // One row per lock name per namespace. This unique constraint is
            // what makes acquisition atomic: the ON CONFLICT clause in
            // PostgresQueueLockStore::acquire() depends on it.
            $table->unique(['namespace_id', 'name'], 'dql_name_unique');

            // Expiry sweep.
            $table->index('expires_at', 'dql_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('dply_queue')->dropIfExists('dply_queue_locks');
    }
};
