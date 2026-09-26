<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Feature flags (Laravel Pennant) were removed on 2026-09-25: every flag had
 * been retired, and the admin flag pages with them. Drop their tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('feature_platform_overrides');
        Schema::dropIfExists('features');
    }

    public function down(): void
    {
        // Irreversible: Pennant and the flag admin are gone.
    }
};
