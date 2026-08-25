<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full-disk server images (snapshots) captured through a cloud provider's API —
 * the "Server images" surface of the unified Snapshots workspace. Semantically
 * distinct from server_database_backups (logical SQL dumps) and redis_snapshots
 * (RDB files): this row tracks a provider-side image and its create→poll
 * lifecycle. See {@see CreateServerImageJob}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_images', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('server_id', 26);
            $table->char('organization_id', 26)->nullable();
            $table->char('user_id', 26)->nullable();
            $table->string('provider', 32);
            // DO snapshot ids are strings; Hetzner image ids are ints — store as
            // string so both fit. Null until the create action completes.
            $table->string('provider_image_id')->nullable();
            $table->string('provider_action_id')->nullable();
            $table->string('name');
            $table->string('status', 32)->default('pending');
            $table->string('region')->nullable();
            $table->bigInteger('bytes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_images');
    }
};
