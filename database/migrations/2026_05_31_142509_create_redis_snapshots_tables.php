<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedicated snapshot pipeline for redis-family cache services on dedicated cache
 * servers (server_role redis/valkey). Parallel to server_database_backups —
 * RDB snapshots are binary point-in-time files and restoration is a file copy +
 * engine restart, semantically distinct from the SQL dump-and-replay pathway.
 *
 * See {@see RedisSnapshotExporter} for the run pipeline
 * (BGSAVE, wait LASTSAVE, scp dump.rdb to /tmp, presigned PUT to S3, drop temp).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redis_snapshots', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('server_id', 26);
            $table->char('server_cache_service_id', 26);
            $table->char('user_id', 26)->nullable();
            $table->char('backup_configuration_id', 26)->nullable();
            $table->string('status', 32)->default('pending');
            // remote_server / destination / control_plane — same enum-values as
            // the database-backup pipeline so the two surfaces stay readable.
            $table->string('storage_kind', 32)->nullable();
            $table->string('disk_path')->nullable();
            $table->string('remote_path')->nullable();
            $table->string('s3_bucket')->nullable();
            $table->string('s3_key', 1024)->nullable();
            $table->bigInteger('bytes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
            $table->index(['server_cache_service_id', 'created_at']);
        });

        Schema::create('redis_snapshot_schedules', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('server_id', 26);
            $table->char('server_cache_service_id', 26);
            $table->char('backup_configuration_id', 26)->nullable();
            $table->string('cron_expression', 64);
            $table->boolean('is_active')->default(true);
            $table->boolean('notify_on_failure')->default(true);
            // Points at the control-plane cron entry that fires
            // `php artisan dply:run-redis-snapshot-schedule {schedule}`.
            $table->char('server_cron_job_id', 26)->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'is_active']);
            $table->unique('server_cache_service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redis_snapshot_schedules');
        Schema::dropIfExists('redis_snapshots');
    }
};
