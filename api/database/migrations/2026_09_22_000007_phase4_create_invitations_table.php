<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Tenant user invitations table.
 *
 * Stores invitations sent to join an organization with an expiring SHA-256 hashed token.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invitations')) {
            Schema::create('invitations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
                $table->string('email')->index();
                $table->string('role', 32)->default('employee');
                $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('token_hash', 64)->unique();
                $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
                $table->timestamp('expires_at');
                $table->timestamp('accepted_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
