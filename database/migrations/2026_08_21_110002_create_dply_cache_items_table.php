<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shared tier's item store.
 *
 * UNLOGGED, and that is the point. `QueueStoreIsolation` exists because the
 * `dply_queue` connection falls through to the primary `DB_*` by default,
 * landing vacuum and WAL pressure on the Postgres serving the dashboard — and
 * a cache is higher churn than a queue. An unlogged table writes no WAL at
 * all, and its price, "truncated on crash recovery", is not a defect for a
 * cache: a cache that empties when the database restarts is a cache.
 *
 * TTL-only, never LRU. LRU in SQL means `DELETE ... ORDER BY last_accessed`,
 * which needs a `last_accessed` write on every read — turning every GET into a
 * write on the very table this migration is tuning down. DynamoDB, whose API
 * this store speaks, is TTL-only for the same reason.
 *
 * See docs/adr/dply-cache.md, decision 5.
 */
return new class extends Migration
{
    private const CONNECTION = 'dply_cache';

    public function up(): void
    {
        if (Schema::connection(self::CONNECTION)->hasTable('dply_cache_items')) {
            return;
        }

        Schema::connection(self::CONNECTION)->create('dply_cache_items', function (Blueprint $table): void {
            $table->char('cache_id', 26);

            // The full client-side key, prefix included. Text rather than a
            // bounded string: Laravel's cache prefix plus a tagged key is not
            // meaningfully bounded, and Postgres stores both identically.
            $table->text('key');

            $table->text('value')->nullable();

            // The DynamoDB AttributeValue discriminator: S | N | B. Preserved
            // rather than inferred, because `get()` reads back through
            // `['S'] ?? ['N']` and a number returned as a string would
            // unserialize to the wrong type.
            $table->char('value_type', 1)->default('S');

            // Unix seconds, matching DynamoDB's TTL attribute. Not a timestamp
            // column: the wire format is a number, and converting twice per
            // request to store it prettily would be a pure cost.
            $table->bigInteger('expires_at');

            // Denormalized so the quota triggers can sum without measuring.
            $table->integer('byte_size')->default(0);

            $table->primary(['cache_id', 'key']);
        });

        /*
         * Quota accounting, deliberately on THIS connection rather than as
         * columns on `managed_caches`.
         *
         * `dply_cache` is meant to point at a database separate from the
         * control plane, and a trigger on the item store cannot reach across
         * databases. A counter on the control-plane row would therefore work
         * in the shared configuration and fail silently in the isolated one —
         * the configuration the doctor actively recommends. Keeping the
         * counter beside the rows it counts makes the two configurations
         * behave identically.
         */
        Schema::connection(self::CONNECTION)->dropIfExists('dply_cache_usage');

        Schema::connection(self::CONNECTION)->create('dply_cache_usage', function (Blueprint $table): void {
            $table->char('cache_id', 26)->primary();
            $table->bigInteger('resident_bytes')->default(0);
            $table->bigInteger('item_count')->default(0);
        });

        if (DB::connection(self::CONNECTION)->getDriverName() !== 'pgsql') {
            return;
        }

        $db = DB::connection(self::CONNECTION);

        // No WAL for cache writes, even when this connection resolves to the
        // control plane's own database.
        $db->statement('ALTER TABLE dply_cache_items SET UNLOGGED');

        // The sweep's predicate. Partial indexes were considered and rejected:
        // the cutoff moves every second, so there is no stable subset to index.
        $db->statement('CREATE INDEX dply_cache_items_expires_at_idx ON dply_cache_items (expires_at)');

        /*
         * Every write rewrites `value` and `expires_at` in place, so the table
         * behaves like the queue's: high update rate, and dead tuples that
         * autovacuum must keep up with or reads start scanning them. Same
         * treatment the jobs table gets, for the same reason.
         */
        $db->statement('ALTER TABLE dply_cache_items SET (
            autovacuum_vacuum_scale_factor = 0.01,
            autovacuum_analyze_scale_factor = 0.01,
            fillfactor = 80
        )');

        /*
         * Quota accounting.
         *
         * STATEMENT-level with transition tables, not row-level. A row-level
         * trigger would issue one UPDATE against `managed_caches` per affected
         * row, so a sweep deleting five thousand expired items would take five
         * thousand locks on a single counter row. Aggregating per statement
         * makes it one UPDATE regardless of how many rows moved.
         *
         * Maintaining this in triggers rather than in the store service is
         * what stops it drifting: the sweeper, a cascade, and a manual DELETE
         * all go through the same accounting as a customer write, and none of
         * them has to remember to.
         *
         * Note the counter is authoritative for *reporting* and for the quota
         * check, but the check itself reads it before the write rather than
         * inside it. Two concurrent writes can therefore both pass at the
         * boundary and overshoot by one item each. That is deliberate: making
         * it exact means serializing every write to a cache behind one row
         * lock, which is a far worse property than a bounded overshoot on a
         * limit whose whole job is to stop unbounded growth.
         */
        $db->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dply_cache_items_usage_delta()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF (TG_OP = 'INSERT') THEN
                    INSERT INTO dply_cache_usage (cache_id, resident_bytes, item_count)
                    SELECT cache_id, SUM(byte_size), COUNT(*)
                      FROM new_items GROUP BY cache_id
                    ON CONFLICT (cache_id) DO UPDATE
                       SET resident_bytes = dply_cache_usage.resident_bytes + EXCLUDED.resident_bytes,
                           item_count     = dply_cache_usage.item_count + EXCLUDED.item_count;

                ELSIF (TG_OP = 'DELETE') THEN
                    UPDATE dply_cache_usage u
                       SET resident_bytes = GREATEST(0, u.resident_bytes - agg.bytes),
                           item_count     = GREATEST(0, u.item_count - agg.rows_)
                      FROM (
                            SELECT cache_id, SUM(byte_size) AS bytes, COUNT(*) AS rows_
                              FROM old_items GROUP BY cache_id
                           ) agg
                     WHERE u.cache_id = agg.cache_id;

                ELSE
                    -- UPDATE: the row count is unchanged, only the size moves.
                    UPDATE dply_cache_usage u
                       SET resident_bytes = GREATEST(0, u.resident_bytes + agg.delta)
                      FROM (
                            SELECT n.cache_id, SUM(n.byte_size - o.byte_size) AS delta
                              FROM new_items n
                              JOIN old_items o
                                ON o.cache_id = n.cache_id AND o.key = n.key
                             GROUP BY n.cache_id
                           ) agg
                     WHERE u.cache_id = agg.cache_id;
                END IF;

                RETURN NULL;
            END;
            $$;
        SQL);

        $db->unprepared('
            CREATE TRIGGER dply_cache_items_ins
            AFTER INSERT ON dply_cache_items
            REFERENCING NEW TABLE AS new_items
            FOR EACH STATEMENT EXECUTE FUNCTION dply_cache_items_usage_delta();

            CREATE TRIGGER dply_cache_items_upd
            AFTER UPDATE ON dply_cache_items
            REFERENCING NEW TABLE AS new_items OLD TABLE AS old_items
            FOR EACH STATEMENT EXECUTE FUNCTION dply_cache_items_usage_delta();

            CREATE TRIGGER dply_cache_items_del
            AFTER DELETE ON dply_cache_items
            REFERENCING OLD TABLE AS old_items
            FOR EACH STATEMENT EXECUTE FUNCTION dply_cache_items_usage_delta();
        ');
    }

    public function down(): void
    {
        $connection = Schema::connection(self::CONNECTION);

        if (DB::connection(self::CONNECTION)->getDriverName() === 'pgsql') {
            DB::connection(self::CONNECTION)->unprepared('
                DROP TRIGGER IF EXISTS dply_cache_items_ins ON dply_cache_items;
                DROP TRIGGER IF EXISTS dply_cache_items_upd ON dply_cache_items;
                DROP TRIGGER IF EXISTS dply_cache_items_del ON dply_cache_items;
                DROP FUNCTION IF EXISTS dply_cache_items_usage_delta();
            ');
        }

        $connection->dropIfExists('dply_cache_items');
        $connection->dropIfExists('dply_cache_usage');
    }
};
