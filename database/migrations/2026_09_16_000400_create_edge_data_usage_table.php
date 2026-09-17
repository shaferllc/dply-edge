<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily D1 and Queues usage per organization (from Cloudflare GraphQL),
 * billed as pass-through usage with the usage markup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_data_usage', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26);
            $table->date('date');
            $table->unsignedBigInteger('d1_rows_read')->default(0);
            $table->unsignedBigInteger('d1_rows_written')->default(0);
            $table->unsignedBigInteger('d1_storage_bytes')->default(0);
            $table->unsignedBigInteger('queue_operations')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'date']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_data_usage');
    }
};
