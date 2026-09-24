<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily key-value usage per store. Read by EdgeKvCost.
 * User request: "continue buuiikding out key value".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_kv_usage', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26);
            $table->char('site_id', 26);
            $table->string('namespace_id');
            $table->date('date');
            $table->unsignedBigInteger('reads')->default(0);
            $table->unsignedBigInteger('writes')->default(0);
            $table->unsignedBigInteger('deletes')->default(0);
            $table->unsignedBigInteger('lists')->default(0);
            $table->unsignedBigInteger('storage_bytes')->default(0);
            $table->timestamps();

            $table->unique(['namespace_id', 'date']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_kv_usage');
    }
};
