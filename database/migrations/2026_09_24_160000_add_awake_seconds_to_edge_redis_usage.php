<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seconds a dply Valkey was awake that day (T-021). Written by
 * EdgeValkeyUsageCollector, priced per second by EdgeRedisCost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_redis_usage', function (Blueprint $table): void {
            $table->unsignedBigInteger('awake_seconds')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('edge_redis_usage', function (Blueprint $table): void {
            $table->dropColumn('awake_seconds');
        });
    }
};
