<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * organizations.spending_cap_cents: the most a paid org agrees to pay for
 * usage past its plan's included credit each billing period. Null = no cap
 * (usage is invoiced, as before); 0 = never more than the plan fee. Past it
 * the org is paused until the next period (StarterUsageBudget).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->unsignedInteger('spending_cap_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('spending_cap_cents'));
    }
};
