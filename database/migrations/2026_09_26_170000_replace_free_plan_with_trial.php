<?php

declare(strict_types=1);

use App\Support\Admin\PlatformAdmins;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Free plan becomes a 5-day Pro trial (ruling r-f17p5zgeh120cm5t).
 *
 * - comped_until: dply's own and hand-picked orgs run as Team with no bill.
 * - billing_paused_at: when an org without a plan was paused; its data is
 *   deleted keep_data_days later (when that is switched on).
 * - billing_notices: which trial / pause emails an org has been sent.
 *
 * Existing orgs: those a platform admin belongs to are comped; every other
 * org without a live subscription gets a fresh trial from now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->timestamp('comped_until')->nullable();
            $table->timestamp('billing_paused_at')->nullable();
            $table->json('billing_notices')->nullable();
        });

        $adminIds = PlatformAdmins::users()->pluck('id')->all();
        if ($adminIds !== []) {
            DB::table('organizations')
                ->whereIn('id', DB::table('organization_user')->whereIn('user_id', $adminIds)->select('organization_id'))
                ->update(['comped_until' => '2099-12-31 00:00:00']);
        }

        DB::table('organizations')
            ->whereNull('comped_until')
            ->whereNotIn('id', DB::table('subscriptions')->whereIn('stripe_status', ['active', 'trialing', 'past_due'])->select('organization_id'))
            ->update(['trial_ends_at' => now()->addDays((int) config('subscription.standard.trial.days', 5))]);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['comped_until', 'billing_paused_at', 'billing_notices']);
        });
    }
};
