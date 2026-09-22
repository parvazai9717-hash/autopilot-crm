<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Additive migration for user security attributes.
 *
 * Adds:
 * - password_changed_at: timestamp when password was last set/changed
 * - must_change_password: force reset flag
 * - failed_login_count: consecutive failed authentication attempts
 * - locked_until: timestamp until which the account cannot log in
 * - mfa_enabled_at: timestamp when MFA was confirmed
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'password_changed_at')) {
                $table->timestamp('password_changed_at')->nullable()->after('password');
            }
            if (!Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false)->after('password_changed_at');
            }
            if (!Schema::hasColumn('users', 'failed_login_count')) {
                $table->unsignedInteger('failed_login_count')->default(0)->after('must_change_password');
            }
            if (!Schema::hasColumn('users', 'locked_until')) {
                $table->timestamp('locked_until')->nullable()->after('failed_login_count');
            }
            if (!Schema::hasColumn('users', 'mfa_enabled_at')) {
                $table->timestamp('mfa_enabled_at')->nullable()->after('locked_until');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columnsToDrop = array_filter([
                'password_changed_at',
                'must_change_password',
                'failed_login_count',
                'locked_until',
                'mfa_enabled_at',
            ], fn (string $col) => Schema::hasColumn('users', $col));

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
