<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Build minutes are a tier allowance (ruling r-zdescb7y05vp1bxx). created_at →
 * published_at includes queue wait and publish, so the build step records its
 * own start and duration; billing sums build_seconds per org per month (the
 * existing organization_id + created_at index covers that query).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_deployments', function (Blueprint $table): void {
            $table->timestamp('build_started_at')->nullable();
            $table->unsignedInteger('build_seconds')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('edge_deployments', function (Blueprint $table): void {
            $table->dropColumn(['build_started_at', 'build_seconds']);
        });
    }
};
