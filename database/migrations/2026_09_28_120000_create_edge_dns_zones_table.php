<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domains whose DNS dply runs: a zone in dply's Cloudflare account that the
 * customer delegates to by changing nameservers. One zone per domain across
 * all of dply — Cloudflare activates a name in one place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_dns_zones', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26);
            $table->string('name', 253)->unique();
            $table->string('cloudflare_zone_id', 64)->nullable()->unique();
            $table->string('status', 16)->default('pending');
            $table->json('name_servers')->nullable();
            $table->json('original_name_servers')->nullable();
            $table->boolean('records_reviewed')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_dns_zones');
    }
};
