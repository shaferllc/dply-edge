<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily Realtime usage per app (docs/edge-realtime.md). Written by
 * EdgeRealtimeUsageCollector, read by EdgeRealtimeCost. realtime_app_id has
 * no foreign key on purpose: deleting an app must not wipe the month's usage
 * before it is billed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_realtime_usage', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26);
            $table->char('site_id', 26)->nullable();
            $table->char('realtime_app_id', 26);
            $table->date('date');
            $table->unsignedBigInteger('connection_seconds')->default(0);
            $table->unsignedBigInteger('messages')->default(0);
            $table->unsignedInteger('peak_connections')->default(0);
            $table->timestamps();

            $table->unique(['realtime_app_id', 'date']);
            $table->index(['organization_id', 'date']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_realtime_usage');
    }
};
