<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dply database detached from every app still runs and bills
 * (DplyDatabases), so its usage rows have no site. Organization and
 * project_id still identify it on the bill (EdgeAppDatabaseCost).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_postgres_usage', function (Blueprint $table) {
            $table->char('site_id', 26)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('edge_postgres_usage', function (Blueprint $table) {
            $table->char('site_id', 26)->nullable(false)->change();
        });
    }
};
