<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages attaches per app: an app's token is made for it, kept encrypted
 * (the deploy sets it as QSTASH_TOKEN), and revoked when the app lets go.
 * Tokens made on the Messages page keep only their hash, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_message_tokens', function (Blueprint $table): void {
            $table->char('site_id', 26)->nullable()->after('organization_id');
            $table->text('secret')->nullable()->after('last4');
            $table->index('site_id');
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('edge_message_tokens', function (Blueprint $table): void {
            $table->dropForeign(['site_id']);
            $table->dropColumn(['site_id', 'secret']);
        });
    }
};
