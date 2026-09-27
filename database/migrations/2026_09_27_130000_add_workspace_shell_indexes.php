<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every workspace page reads the site's primary domain (site_domains by
 * site_id) and the user's organizations (organization_user by user_id — the
 * primary key leads with organization_id, so it can't serve that lookup).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS site_domains_site_id_is_primary_index ON site_domains (site_id, is_primary)');
        DB::statement('CREATE INDEX IF NOT EXISTS organization_user_user_id_index ON organization_user (user_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS site_domains_site_id_is_primary_index');
        DB::statement('DROP INDEX IF EXISTS organization_user_user_id_index');
    }
};
