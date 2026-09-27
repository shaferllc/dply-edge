<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usage is billed in arrears, once per Stripe billing period (UsageInvoicer):
 *
 * - subscriptions.current_period_*: the Stripe period, so "this period" on the
 *   billing page matches what the next invoice will charge.
 * - billing_usage_charges: one row per org and period — the idempotency key for
 *   the usage lines added to the renewal (or final) invoice, and their record.
 * - organizations.usage_alert_cents / usage_alerts: the usage soft limit an
 *   owner is emailed about at 50/80/100% (null = 2× the plan price), and which
 *   threshold was last sent for which period.
 * - usage tables keep their rows when a site is deleted (site_id set null
 *   instead of cascading), so a deleted site's usage still reaches the invoice.
 */
return new class extends Migration
{
    private const SITE_USAGE_TABLES = ['edge_usage_snapshots', 'edge_container_usage', 'edge_redis_usage', 'edge_kv_usage', 'edge_postgres_usage'];

    public function up(): void
    {
        foreach (self::SITE_USAGE_TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropForeign(['site_id']);
                $table->char('site_id', 26)->nullable()->change();
                $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            });
        }

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->unsignedInteger('usage_alert_cents')->nullable();
            $table->json('usage_alerts')->nullable();
        });

        Schema::create('billing_usage_charges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26);
            $table->date('period_start');
            $table->date('period_end'); // last day included
            $table->string('tier', 16);
            $table->string('status', 16); // pending | billed | empty | legacy
            $table->unsignedInteger('cents')->default(0);
            $table->json('lines')->nullable();
            $table->string('stripe_invoice_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'period_start']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_usage_charges');
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['usage_alert_cents', 'usage_alerts']));
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn(['current_period_start', 'current_period_end']));
        foreach (self::SITE_USAGE_TABLES as $name) {
            DB::table($name)->whereNull('site_id')->delete();
            Schema::table($name, function (Blueprint $table): void {
                $table->dropForeign(['site_id']);
                $table->char('site_id', 26)->nullable(false)->change();
                $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            });
        }
    }
};
