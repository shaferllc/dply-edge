<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily HTTP delivery messages and bandwidth per app.
 * Read by EdgeDeliveryCost. Written by the delivery usage hook.
 * User request: "ok then lets build that out and we need to charge for it".
 */
return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        Schema::dropIfExists('edge_delivery_usage');
    }
};
