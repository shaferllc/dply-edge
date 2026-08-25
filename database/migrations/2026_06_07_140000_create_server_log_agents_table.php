<?php

declare(strict_types=1);

use App\Models\Server;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per server that has opted into the dply Logs add-on. Tracks the
 * lifecycle of the edge Vector agent (install → running), which sources are
 * enabled, the installed Vector version, and the most recent install output for
 * the workspace's streaming progress view.
 *
 * One agent per server (unique server_id) — the add-on is a per-server resource,
 * billed per-server. See docs/SERVER_LOGS_ADDON.md and {@see ServerLogAgent}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_log_agents', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('server_id', 26);

            // pending → installing → running → (stopped|failed) ; uninstalling on teardown
            $table->string('status', 32)->default('pending');

            // Installed Vector version (parsed from `vector --version` post-install).
            $table->string('version', 64)->nullable();

            // Subset of config('server_logs.sources') keys the customer has ON.
            // Null = "use config defaults" (resolved by the model accessor).
            $table->json('enabled_sources')->nullable();

            // SHA-256 fingerprint of the per-server mTLS client cert, set once the
            // PKI slice issues + deploys a cert. Nullable through Phase 1 early work.
            $table->string('client_cert_fingerprint', 95)->nullable();

            // Last time the aggregator saw bytes from this server (heartbeat of
            // "logs are actually flowing"); populated once the data plane exists.
            $table->timestamp('last_seen_at')->nullable();

            $table->text('error_message')->nullable();
            $table->text('install_output')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on((new Server)->getTable())->cascadeOnDelete();
            $table->unique('server_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_log_agents');
    }
};
