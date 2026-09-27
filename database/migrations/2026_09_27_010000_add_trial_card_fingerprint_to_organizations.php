<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The card (Stripe payment_method.card.fingerprint) an org's trial was taken
 * with, so a second org paying with the same card starts paid instead of on
 * another trial (CaptureTrialCardFingerprint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('trial_card_fingerprint', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('trial_card_fingerprint');
        });
    }
};
