<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jobs that exhausted their attempts on a dply Queue namespace.
 *
 * Laravel's default `FailedJobProviderInterface` writes to the app's OWN
 * database. On a serverless function backed by SQLite that means a
 * per-container `/tmp` file — the failure is written and then vanishes with
 * the container. Fixing the queue while leaving that alone would reproduce,
 * one layer up, the exact silent-failure class the queue pump's backend
 * classifier exists to eliminate.
 *
 * So failures are recorded here instead, and this table is what
 * `queue:failed` / `queue:retry` act on once the companion package ships.
 *
 * Unlike `dply_queue_jobs`, this one IS append-only, so it follows the
 * `function_invocations` convention exactly: ULID PK, unconstrained
 * `foreignUlid` index, `created_at` only, bounded widths, and one composite
 * index serving both the UI listing and the prune sweep.
 */
return new class extends Migration
{
    protected $connection = 'dply_queue';

    public function up(): void
    {
        if (Schema::connection('dply_queue')->hasTable('dply_queue_failed_jobs')) {
            return;
        }

        Schema::connection('dply_queue')->create('dply_queue_failed_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('namespace_id');

            // Laravel's own job uuid from the envelope — the handle a retry
            // is addressed by.
            $table->string('job_uuid', 64)->nullable();

            $table->string('queue', 128);
            $table->longText('payload');
            $table->longText('exception')->nullable();
            $table->string('display_name', 255)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            // Set when the job has been pushed back onto the queue, so the UI
            // can tell "still failed" from "retried".
            $table->timestampTz('retried_at', 6)->nullable();

            $table->timestampTz('failed_at', 6)->nullable();
            $table->timestampTz('created_at', 6)->nullable();

            // Serves the newest-first listing and the age-based prune sweep.
            $table->index(['namespace_id', 'failed_at'], 'dqfj_listing_idx');
            $table->unique(['namespace_id', 'job_uuid'], 'dqfj_uuid_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('dply_queue')->dropIfExists('dply_queue_failed_jobs');
    }
};
