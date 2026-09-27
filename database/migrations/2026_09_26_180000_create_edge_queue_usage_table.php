<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily billable operations per Cloudflare queue, so a queue's sheet and card
 * show its own cost. The organization total stays in edge_data_usage.
 * Filled by EdgeDataUsageCollector.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_queue_usage', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26);
            $table->string('queue_id');
            $table->date('date');
            $table->unsignedBigInteger('operations')->default(0);
            $table->timestamps();

            $table->unique(['queue_id', 'date']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_queue_usage');
    }
};
