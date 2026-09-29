<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_container_usage', function (Blueprint $table) {
            // Null = the app's Worker does not count replies yet (deployed before it did).
            $table->unsignedBigInteger('reply_bytes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('edge_container_usage', function (Blueprint $table) {
            $table->dropColumn('reply_bytes');
        });
    }
};
