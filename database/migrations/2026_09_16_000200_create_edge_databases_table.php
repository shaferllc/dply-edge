<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D1 databases an organization created from Projects → Databases. They live
 * in dply's Cloudflare account, so this table is what scopes them per org.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_databases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26);
            $table->string('name', 64);
            $table->string('cloudflare_id', 64)->unique();
            $table->string('location_hint', 16)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_databases');
    }
};
