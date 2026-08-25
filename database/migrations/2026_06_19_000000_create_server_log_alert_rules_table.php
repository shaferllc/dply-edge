<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Server;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * dply Logs alerting (paid tier) — customer-defined rules that fire a
 * notification when shipped logs cross a threshold over a rolling window
 * ("> N error lines in 5 min", "any line matching 'OOMKilled' appeared").
 *
 * Many rules per server (unlike the one-agent-per-server row). Evaluated by
 * {@see \App\Modules\Logs\Console\EvaluateLogAlertsCommand} on a schedule, gated
 * on the org's `alerting_enabled` entitlement. See docs/SERVER_LOGS_BILLING.md
 * and {@see ServerLogAlertRule}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_log_alert_rules', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('server_id', 26);
            // Denormalised owning org — lets the evaluator gate on the
            // alerting entitlement without walking the server relation per rule.
            $table->char('organization_id', 26);

            $table->string('name');

            // 'rate'    → count of matching lines >= threshold over the window
            // 'pattern' → a line matching `search` appeared (threshold defaults 1)
            $table->string('type', 16)->default('rate');

            // Optional ClickHouse facet filters (same vocabulary as the explorer).
            $table->string('level', 32)->nullable();
            $table->string('source', 32)->nullable();
            $table->string('search')->nullable();

            $table->unsignedInteger('threshold')->default(1);
            $table->unsignedInteger('window_minutes')->default(5);
            // Min gap between fires so a sustained breach doesn't spam channels.
            $table->unsignedInteger('cooldown_minutes')->default(60);

            $table->boolean('enabled')->default(true);

            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('last_fired_at')->nullable();
            // Most recent matched count, surfaced in the rule list.
            $table->unsignedInteger('last_count')->nullable();

            $table->timestamps();

            $table->foreign('server_id')->references('id')->on((new Server)->getTable())->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on((new Organization)->getTable())->cascadeOnDelete();
            $table->index(['server_id', 'enabled']);
            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_log_alert_rules');
    }
};
