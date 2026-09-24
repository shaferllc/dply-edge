<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily Postgres compute seconds and storage byte-hours per project.
 * Read by EdgeAppDatabaseCost.
 * User request: "ok lets move ahead with imp,emeting neon and postgres first".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_postgres_usage', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26);
            $table->char('site_id', 26);
            $table->string('project_id');
            $table->date('date');
            $table->unsignedBigInteger('compute_unit_seconds')->default(0);
            $table->unsignedBigInteger('storage_byte_hours')->default(0);
            $table->unsignedBigInteger('history_byte_hours')->default(0);
            $table->unsignedBigInteger('snapshot_byte_hours')->default(0);
            $table->unsignedBigInteger('transfer_bytes')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'date']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_postgres_usage');
    }
};
