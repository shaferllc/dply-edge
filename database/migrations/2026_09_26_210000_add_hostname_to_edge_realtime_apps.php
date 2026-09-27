<?php

use App\Models\EdgeRealtimeApp;
use App\Modules\Edge\Services\Realtime\EdgeRealtimeApps;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each Realtime app's own host, {label}.realtime.dply.io (docs/edge-realtime.md).
 * Existing rows are backfilled here; their KV records pick the hostname up on
 * the next sync (the relay 404s a per-app host until then).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_realtime_apps', function (Blueprint $table): void {
            $table->string('hostname')->nullable()->unique();
        });

        EdgeRealtimeApp::query()->whereNull('hostname')->with('site')->orderBy('created_at')->each(function (EdgeRealtimeApp $app): void {
            $app->forceFill(['hostname' => EdgeRealtimeApps::uniqueHostname(EdgeRealtimeApps::labelFor($app->site, $app->name))])->save();
        });
    }

    public function down(): void
    {
        Schema::table('edge_realtime_apps', function (Blueprint $table): void {
            $table->dropUnique(['hostname']);
            $table->dropColumn('hostname');
        });
    }
};
