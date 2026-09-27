<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day's D1 usage per database id ({id: {rows_read, rows_written,
 * storage_bytes}}), next to the organization's totals, so one database's
 * cost can be shown on its own. Written by EdgeDataUsageCollector.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_data_usage', function (Blueprint $table): void {
            $table->jsonb('d1_by_database')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('edge_data_usage', function (Blueprint $table): void {
            $table->dropColumn('d1_by_database');
        });
    }
};
