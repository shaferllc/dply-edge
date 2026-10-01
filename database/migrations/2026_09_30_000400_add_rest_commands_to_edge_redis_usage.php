<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commands a dply Valkey served over its REST API (valkey-gateway rest.go),
 * per app and day, billed per 100K (EdgeRedisCost). A new column rather than
 * the Upstash-era `commands`, which older rows may still hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_redis_usage', function (Blueprint $table): void {
            $table->unsignedBigInteger('rest_commands')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('edge_redis_usage', function (Blueprint $table): void {
            $table->dropColumn('rest_commands');
        });
    }
};
