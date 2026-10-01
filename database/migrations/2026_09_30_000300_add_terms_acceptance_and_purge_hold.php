<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users.terms_version / terms_accepted_at: which version of the Terms,
 * Privacy Policy and AUP (config legal.version) a user accepted, and when.
 * organizations.purge_hold_at / purge_hold_reason: a legal hold; while set,
 * the billing purge never deletes the organization's data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('terms_version', 32)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
        });
        Schema::table('organizations', function (Blueprint $table): void {
            $table->timestamp('purge_hold_at')->nullable();
            $table->string('purge_hold_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['terms_version', 'terms_accepted_at']);
        });
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['purge_hold_at', 'purge_hold_reason']);
        });
    }
};
