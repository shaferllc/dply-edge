<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cloudflare Queues an organization created from Projects → Queues. They live
 * in dply's Cloudflare account, so this table scopes them per org.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_queues', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26);
            $table->string('name', 64);
            $table->string('cloudflare_id', 64)->unique();
            $table->string('cloudflare_name', 128)->unique();
            $table->char('created_by', 26)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_queues');
    }
};
