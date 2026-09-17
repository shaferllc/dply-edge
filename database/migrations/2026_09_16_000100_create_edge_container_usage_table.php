<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily container compute per site, from Cloudflare's
 * containersUsageAdaptiveGroups (the dataset behind Cloudflare's own
 * invoice). Billing prices it per second of vCPU, memory and disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_container_usage', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26)->index();
            $table->char('site_id', 26);
            $table->date('date');
            $table->string('application_id', 64)->nullable();
            $table->double('cpu_seconds')->default(0);
            $table->double('memory_gib_seconds')->default(0);
            $table->double('disk_gb_seconds')->default(0);
            $table->unsignedBigInteger('tx_bytes')->default(0);
            $table->timestamps();

            $table->unique(['site_id', 'date']);
            $table->index(['organization_id', 'date']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_container_usage');
    }
};
