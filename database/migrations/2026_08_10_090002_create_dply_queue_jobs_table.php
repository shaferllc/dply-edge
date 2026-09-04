<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * dply Queue job rows — the data plane.
 *
 * Runs on the `dply_queue` connection, not the primary: customer payloads are
 * arbitrary data we should not co-locate with the control plane, and this
 * table needs autovacuum settings that would be hostile to share.
 * (docs/adr/dply-queue.md, decision 8.)
 *
 * ## The one-column visibility model
 *
 * Laravel's `DatabaseQueue` splits this into `available_at` + `reserved_at`
 * and then expresses availability as a disjunction — available OR
 * reserved-but-expired. An OR across two timestamp columns cannot be served
 * by one index range, which is a real reason that driver struggles under
 * load.
 *
 * `visible_at` is instead a single "earliest moment this row may be claimed":
 *
 *   push          → now() + delay
 *   reserve       → now() + lease
 *   release(d)    → now() + d
 *   ack           → row deleted
 *
 * The pop predicate becomes one range scan, and an expired lease is
 * indistinguishable from availability — so **expired reservations need no
 * sweeper**. This is SQS's model, which is also why an SQS-compatible API
 * maps onto it cleanly.
 *
 * `state` exists only so the Laravel Queue contract's pendingSize /
 * delayedSize / reservedSize can be answered off a separate index, well away
 * from the hot path.
 *
 * ## Why this table is NOT shaped like `function_invocations`
 *
 * That table is append-only, and its conventions assume it. This one is a
 * churn table: insert, update, delete per row. What carries over is the ULID
 * PK, the un-constrained `foreignUlid` index, bounded widths, and one
 * composite index serving the hot path. What does not carry over is
 * `created_at`-only (a reserve must write timestamps) and "volume bounded by
 * pruning" — volume here is bounded by consumption and by a push rejection at
 * `max_queue_depth`.
 */
return new class extends Migration
{
    protected $connection = 'dply_queue';

    public function up(): void
    {
        if (Schema::connection('dply_queue')->hasTable('dply_queue_jobs')) {
            return;
        }

        Schema::connection('dply_queue')->create('dply_queue_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // No FK: the namespace lives on a different connection in
            // production, so the constraint is not expressible — which
            // matches the house convention of indexing without constraining
            // anyway.
            $table->ulid('namespace_id');

            $table->string('queue', 128);
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts')->default(0);

            /**
             * The single visibility column. Everything hangs off this.
             *
             * Microsecond precision is load-bearing, not decoration. At the
             * default precision of 0 Postgres ROUNDS: a zero-delay push at
             * 15:41:00.83 is stored as 15:41:01, i.e. a fifth of a second in
             * the future, so the job is briefly unclaimable and a
             * push-then-claim races. Every comparison here is against `now()`
             * at full precision, so the column must match it.
             */
            $table->timestampTz('visible_at', 6);

            $table->timestampTz('reserved_at', 6)->nullable();

            /**
             * Fencing token, reissued on every claim. Ack / release / fail
             * must present the one they were handed.
             *
             * Without it: worker A reserves a job, stalls past its lease,
             * worker B re-claims and starts running it, then A wakes and acks
             * — deleting B's reservation. The job disappears with no failure
             * record and no trace. The uuid makes that a no-op with a 409
             * instead.
             */
            $table->uuid('reservation_id')->nullable();

            // 0 = queued/delayed, 1 = reserved. Stats only, never the hot path.
            $table->smallInteger('state')->default(0);

            /**
             * Read out of the job envelope at push time. Laravel serializes
             * these in plaintext (only `data.command` is encrypted), so we get
             * them for free — and `job_timeout` is what lets the server clamp
             * the lease so a `retry_after < timeout` misconfiguration cannot
             * be expressed here at all.
             */
            $table->string('job_uuid', 64)->nullable();
            $table->unsignedInteger('job_timeout')->nullable();
            $table->unsignedSmallInteger('job_max_tries')->nullable();
            $table->string('batch_id', 64)->nullable();
            $table->string('display_name', 255)->nullable();

            $table->unsignedInteger('payload_bytes')->default(0);
            $table->timestampTz('created_at', 6)->nullable();

            // The hot path: claim the next visible job for one namespace+queue.
            // Column order matters — equality, equality, then range.
            $table->index(['namespace_id', 'queue', 'visible_at', 'id'], 'dqj_claim_idx');

            // Depth/stats queries and the per-namespace depth guard.
            $table->index(['namespace_id', 'state', 'visible_at'], 'dqj_stats_idx');
        });

        /**
         * `visible_at` is indexed and rewritten on every single reserve, so
         * no update here can be HOT: each claim writes a new heap tuple AND a
         * new index entry, and each ack deletes both. That is the classic
         * Postgres-as-a-queue failure mode — dead tuples accumulate faster
         * than default autovacuum will collect them, the index bloats, and
         * claim latency climbs until someone vacuums by hand.
         *
         * These settings are not an optimisation; without them this table
         * degrades under sustained load.
         */
        DB::connection('dply_queue')->statement('
            ALTER TABLE dply_queue_jobs SET (
                autovacuum_vacuum_scale_factor  = 0.01,
                autovacuum_analyze_scale_factor = 0.01,
                autovacuum_vacuum_cost_delay    = 0,
                fillfactor                      = 80
            )
        ');
    }

    public function down(): void
    {
        Schema::connection('dply_queue')->dropIfExists('dply_queue_jobs');
    }
};
