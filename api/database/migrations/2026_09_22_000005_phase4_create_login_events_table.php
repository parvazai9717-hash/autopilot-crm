<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Login events audit log.
 *
 * Immutable append-only audit trail for authentication events:
 * - login_success
 * - login_failed
 * - locked_out
 * - password_reset_requested
 * - password_reset_completed
 * - password_changed
 * - force_reset
 * - token_revoked
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('login_events')) {
            Schema::create('login_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('org_id')->nullable()->constrained('organizations')->nullOnDelete();
                $table->string('email')->index();
                $table->string('event_type', 64)->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
    }
};
