<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Edge Forms submissions (docs/site/forms.md). Written by
 * EdgeFormIngestController after the Worker screens and signs a POST; shown on
 * the app's Forms page. Deleted with the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_form_submissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('site_id', 26);
            $table->string('path');
            $table->jsonb('fields');
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_form_submissions');
    }
};
