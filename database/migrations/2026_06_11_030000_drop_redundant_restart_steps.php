<?php

use Illuminate\Database\Migrations\Migration;

/**
 * No-op since the dply-edge cut (2026-08-25).
 *
 * This was a data migration over VM / serverless rows — the models and tables
 * it rewrote left with those product lines, so there is nothing to migrate.
 * Kept as a file so the migrations table stays consistent for installs that
 * already ran it.
 */
return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
