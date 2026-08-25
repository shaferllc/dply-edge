<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted master↔replica edges between two {@see ServerCacheService} rows.
 *
 * Each row represents "this replica points at this master". One master can have many
 * replicas; a replica points at exactly one master (`replica_cache_service_id` UNIQUE).
 * The {@see PollCacheServiceReplicationCommand} periodic job
 * keeps `last_link_status` / `last_observed_offset` fresh by polling
 * `INFO replication` on the replica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_cache_service_replications', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('master_cache_service_id', 26);
            $table->char('replica_cache_service_id', 26);
            // configuring → active → error / teardown
            $table->string('status', 32)->default('configuring');
            $table->string('last_link_status', 32)->nullable();
            $table->bigInteger('last_observed_offset')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('master_cache_service_id');
            $table->unique('replica_cache_service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_cache_service_replications');
    }
};
