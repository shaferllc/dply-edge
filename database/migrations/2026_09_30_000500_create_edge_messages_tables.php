<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * dply Messages (packages/messages-worker, QStash-compatible), behind the
 * `resource-messages` flag. An account per organization holds its signing
 * keys (encrypted; they sign every delivery and the app needs them to
 * verify). Tokens keep only a hash and the last four characters: the token
 * is shown once. Usage is published messages per day, billed per 100K.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_message_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26)->unique();
            $table->text('current_signing_key');
            $table->text('next_signing_key');
            $table->unsignedBigInteger('published_counter')->default(0);
            $table->timestamps();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
        Schema::create('edge_message_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26);
            $table->string('label');
            $table->string('token_hash', 64)->unique();
            $table->string('last4', 4);
            $table->char('created_by', 26)->nullable();
            $table->timestamps();
            $table->index('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
        Schema::create('edge_message_usage', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26);
            $table->date('date');
            $table->unsignedBigInteger('messages')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'date']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_message_usage');
        Schema::dropIfExists('edge_message_tokens');
        Schema::dropIfExists('edge_message_accounts');
    }
};
