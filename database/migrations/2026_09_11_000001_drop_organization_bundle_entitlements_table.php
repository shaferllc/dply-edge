<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The bundled-products feature (free Tracely + Lookout workspaces) was deleted
 * on 2026-09-11 — it was off by default and its qualifying plan no longer
 * exists. Owner decision: drop its table too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('organization_bundle_entitlements');
    }

    public function down(): void
    {
        // Irreversible: the feature and its model are gone.
    }
};
