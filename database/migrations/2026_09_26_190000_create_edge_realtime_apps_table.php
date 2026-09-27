<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Realtime (Reverb-compatible) apps on the customer relay. The row is the
 * source of truth; EdgeRealtimeApps writes it into the relay's KV namespace.
 * See docs/edge-realtime.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_realtime_apps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26);
            $table->char('site_id', 26)->nullable();
            $table->string('name');
            $table->string('app_key')->unique();
            $table->text('app_secret');
            $table->string('status')->default('active');
            $table->unsignedInteger('max_connections');
            $table->json('allowed_origins')->nullable();
            $table->boolean('client_events')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('site_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_realtime_apps');
    }
};
