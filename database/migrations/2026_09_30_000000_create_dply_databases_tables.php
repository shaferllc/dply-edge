<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * dply-managed Postgres / MySQL / MongoDB databases as organization resources
 * (App\Models\DplyDatabase), attached to one or more apps. An app's primary
 * is still mirrored at meta.edge.database for everything that reads it.
 * Existing databases are adopted on first read (DplyDatabases::for), so this
 * only adds tables and rolls back cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dply_databases', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26)->index();
            $table->string('name', 40);
            $table->string('engine', 16);
            $table->string('remote_id')->unique();
            $table->string('region', 32);
            $table->string('host');
            $table->string('size', 8);
            $table->integer('suspend');
            $table->integer('disk_gb');
            $table->text('password'); // encrypted cast
            // A database's own panel state (history, backup, memory, usage
            // counters) while it is not an app's primary.
            $table->json('state')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('dply_database_site', function (Blueprint $table) {
            $table->id();
            $table->char('dply_database_id', 26);
            $table->char('site_id', 26)->index();
            // Env prefix for a non-primary database (ANALYTICS -> ANALYTICS_DB_HOST …).
            $table->string('env_name', 40);
            $table->boolean('primary')->default(false);
            $table->timestamps();
            $table->unique(['dply_database_id', 'site_id']);
            $table->foreign('dply_database_id')->references('id')->on('dply_databases')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dply_database_site');
        Schema::dropIfExists('dply_databases');
    }
};
