<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The org Activity page reads an org's audit rows newest-first
 * (organization_id = ? ORDER BY created_at DESC). Postgres walked the global
 * created_at index and filtered out every other org's rows. The composite
 * index serves that directly and covers organization_id lookups, so it
 * replaces the single-column one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS audit_logs_organization_id_created_at_index ON audit_logs (organization_id, created_at)');
        DB::statement('DROP INDEX IF EXISTS audit_logs_organization_id_index');
    }

    public function down(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS audit_logs_organization_id_index ON audit_logs (organization_id)');
        DB::statement('DROP INDEX IF EXISTS audit_logs_organization_id_created_at_index');
    }
};
