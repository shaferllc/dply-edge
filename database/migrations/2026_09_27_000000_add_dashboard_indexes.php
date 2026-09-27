<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard lists an org's sites (sites.organization_id) and every
 * workspace tab reads a site's newest deployments (site_id, created_at desc);
 * neither had an index. IF NOT EXISTS so a hand-built index doesn't block it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS sites_organization_id_index ON sites (organization_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS edge_deployments_site_id_created_at_index ON edge_deployments (site_id, created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sites_organization_id_index');
        DB::statement('DROP INDEX IF EXISTS edge_deployments_site_id_created_at_index');
    }
};
