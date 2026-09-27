<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily Cloudflare usage that used to go unbilled, one row per Worker script
 * or customer R2 bucket: Workers CPU, Durable Objects, customer object
 * storage, Images transformations. Written by EdgePlatformUsageCollector, read by EdgePlatformUsageCost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_platform_usage', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26);
            $table->char('site_id', 26)->nullable();
            // Worker script name or R2 bucket name.
            $table->string('resource');
            $table->date('date');
            $table->unsignedBigInteger('cpu_ms')->default(0);
            $table->unsignedBigInteger('do_requests')->default(0);
            $table->double('do_gb_seconds')->default(0);
            $table->unsignedBigInteger('do_rows_read')->default(0);
            $table->unsignedBigInteger('do_rows_written')->default(0);
            $table->unsignedBigInteger('do_storage_bytes')->default(0);
            $table->unsignedBigInteger('r2_storage_bytes')->default(0);
            $table->unsignedBigInteger('r2_class_a_ops')->default(0);
            $table->unsignedBigInteger('r2_class_b_ops')->default(0);
            $table->unsignedBigInteger('images_transformations')->default(0);
            $table->timestamps();

            $table->unique(['resource', 'date']);
            $table->index(['organization_id', 'date']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            // A deleted app's usage still bills, like edge_realtime_usage.
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_platform_usage');
    }
};
