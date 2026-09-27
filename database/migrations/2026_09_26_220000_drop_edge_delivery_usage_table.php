<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop edge_delivery_usage: the QStash-backed HTTP delivery resource was
 * removed (owner: "Remove qstash"). No app used it and the table was empty.
 * down() recreates it as 2026_09_24_120000_create_edge_delivery_usage_table did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('edge_delivery_usage');
    }

    public function down(): void
    {
        Schema::create('edge_delivery_usage', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26);
            $table->char('site_id', 26);
            $table->date('date');
            $table->unsignedBigInteger('messages')->default(0);
            $table->unsignedBigInteger('bandwidth_bytes')->default(0);
            $table->timestamps();

            $table->unique(['site_id', 'date']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }
};
