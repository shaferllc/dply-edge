<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S3 keys for object storage buckets: Cloudflare account API tokens scoped
 * to R2 buckets (EdgeBucketKeys). An app key (site_id set) covers the app's
 * awake buckets and is injected as AWS_* on deploy, so its derived secret is
 * kept, encrypted. An external key (site_id null) covers one bucket and its
 * secret is shown once and never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_bucket_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26);
            $table->char('site_id', 26)->nullable();
            $table->string('label');
            $table->json('buckets');
            $table->string('access')->default('write');
            $table->string('token_id')->unique();
            $table->text('secret')->nullable();
            $table->string('secret_last4', 4);
            $table->string('status')->default('active');
            $table->char('created_by', 26)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'site_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_bucket_keys');
    }
};
