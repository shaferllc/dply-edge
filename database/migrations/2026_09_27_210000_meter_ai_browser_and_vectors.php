<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workers AI, Browser Rendering and Vectorize, metered per app (EdgeMeter):
 * - edge_platform_usage gets their columns. The Worker and container proxies
 *   add to a `meter:{site}` row per day; the collector writes stored vector
 *   dimensions on a `vectorize:{index}` row.
 * - organizations.metered_cap_cents: the org's monthly spend cap for the
 *   three (null = the default, 0 = off below the platform ceiling);
 *   metered_cap_alerts: the 80%/100% emails already sent this period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_platform_usage', function (Blueprint $table): void {
            $table->double('ai_neurons')->default(0);
            $table->unsignedBigInteger('browser_ms')->default(0);
            $table->unsignedBigInteger('vector_query_dims')->default(0);
            $table->unsignedBigInteger('vector_stored_dims')->default(0);
        });
        Schema::table('organizations', function (Blueprint $table): void {
            $table->unsignedInteger('metered_cap_cents')->nullable();
            $table->json('metered_cap_alerts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('edge_platform_usage', fn (Blueprint $table) => $table->dropColumn(['ai_neurons', 'browser_ms', 'vector_query_dims', 'vector_stored_dims']));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['metered_cap_cents', 'metered_cap_alerts']));
    }
};
