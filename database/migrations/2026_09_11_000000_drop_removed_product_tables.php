<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop the tables of products removed in the Edge-only cut (2026-08-25).
 *
 * Owner decision 2026-09-11 (T-007): drop + squash. Each table here is created
 * by a migration but referenced by no live model, factory, seeder, config or
 * route. CASCADE also removes the four foreign keys live tables still hold into
 * them (servers.private_network_id, sites.active_deploy_pipeline_id,
 * site_deploy_hooks.pipeline_id, site_deploy_steps.pipeline_id); the columns
 * themselves are left in place.
 *
 * Kept after `schema:dump --prune` on purpose: fresh installs load the dump,
 * which already records this migration as run; existing installs have not run
 * it, so they pick it up and drop the tables.
 */
return new class extends Migration
{
    private const TABLES = [
        'ai_advisor_runs', 'ai_credentials', 'backup_download_stagings', 'cache_site',
        'captcha_credentials', 'cloud_buckets', 'cloud_database_trusted_sources', 'cloud_databases',
        'cloud_deploy_task_runs', 'connected_app_credentials', 'deploy_intelligence_alerts',
        'dply_queue_fleets', 'dply_queue_namespace_usage_daily', 'dply_queue_namespaces',
        'dply_queue_usage_daily', 'dply_queue_workers', 'error_tracking_credentials', 'feedback_reports',
        'load_balancer_services', 'load_balancer_targets', 'log_drain_credentials', 'mail_credentials',
        'managed_caches', 'oauth_credentials', 'object_storage_credentials', 'payment_credentials',
        'private_networks', 'production_data_connections', 'quick_downloads', 'realtime_apps',
        'redis_snapshots', 'roadmap_ai_runs', 'roadmap_items', 'roadmap_releases', 'roadmap_suggestions',
        'scheduled_deploys', 'scheduler_tick_outputs', 'search_credentials', 'server_blueprints',
        'server_cache_service_replications', 'server_command_runs', 'server_credential_shares',
        'server_images', 'server_log_agents', 'server_log_aggregators', 'server_log_alert_rules',
        'server_log_usage_daily', 'server_note_comments', 'server_notes', 'server_pool_members',
        'server_remote_access_events', 'server_ssh_sessions', 'server_wildcard_certificates',
        'serverless_failed_jobs', 'serverless_usage_snapshots', 'service_credentials',
        'site_deploy_pipelines', 'site_deployment_schedules', 'site_worker_pool', 'sms_credentials',
        'worker_pools',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement(sprintf('DROP TABLE IF EXISTS "%s" CASCADE', $table));
        }
    }

    public function down(): void
    {
        // Irreversible: the data is gone and the removed products have no code
        // to recreate these tables for.
    }
};
