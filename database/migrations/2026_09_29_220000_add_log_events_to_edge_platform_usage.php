<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_platform_usage', function (Blueprint $table) {
            $table->unsignedBigInteger('log_events')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('edge_platform_usage', function (Blueprint $table) {
            $table->dropColumn('log_events');
        });
    }
};
