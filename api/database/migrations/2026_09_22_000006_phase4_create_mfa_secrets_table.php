<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — MFA secrets table.
 *
 * Stores encrypted TOTP seed secrets and encrypted one-time recovery codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mfa_secrets')) {
            Schema::create('mfa_secrets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->unique();
                $table->text('secret_encrypted');
                $table->text('recovery_codes_encrypted')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_secrets');
    }
};
