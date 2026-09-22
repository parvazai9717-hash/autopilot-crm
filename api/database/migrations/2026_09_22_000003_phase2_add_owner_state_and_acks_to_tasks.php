<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — additive migration.
 *
 * Adds:
 * - owner_state: enum/string ('resolved', 'ambiguous', 'unmatched', 'missing')
 * - no_deadline_ack: boolean flag acknowledging missing deadline
 * - conditional_ack: boolean flag acknowledging conditional dependency
 * - fingerprint: sha256 hash of (title + owner + meeting) to prevent duplication
 *
 * Safe and additive — backfills existing records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('tasks', 'owner_state')) {
                $table->string('owner_state', 32)->default('missing')->after('owner_ambiguous');
            }
            if (!Schema::hasColumn('tasks', 'no_deadline_ack')) {
                $table->boolean('no_deadline_ack')->default(false)->after('deadline_phrase');
            }
            if (!Schema::hasColumn('tasks', 'conditional_ack')) {
                $table->boolean('conditional_ack')->default(false)->after('conditional');
            }
            if (!Schema::hasColumn('tasks', 'fingerprint')) {
                $table->string('fingerprint', 64)->nullable()->after('source_text');
                $table->index('fingerprint', 'tasks_fingerprint_idx');
            }
        });

        // Backfill owner_state on existing records
        DB::table('tasks')
            ->whereNotNull('owner_id')
            ->where('owner_ambiguous', false)
            ->update(['owner_state' => 'resolved']);

        DB::table('tasks')
            ->where('owner_ambiguous', true)
            ->update(['owner_state' => 'ambiguous']);

        DB::table('tasks')
            ->whereNull('owner_id')
            ->whereNotNull('owner_name_raw')
            ->where('owner_name_raw', '!=', '')
            ->update(['owner_state' => 'unmatched']);

        DB::table('tasks')
            ->whereNull('owner_id')
            ->where(function ($q) {
                $q->whereNull('owner_name_raw')->orWhere('owner_name_raw', '');
            })
            ->update(['owner_state' => 'missing']);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'fingerprint')) {
                $table->dropIndex('tasks_fingerprint_idx');
                $table->dropColumn('fingerprint');
            }
            if (Schema::hasColumn('tasks', 'conditional_ack')) {
                $table->dropColumn('conditional_ack');
            }
            if (!Schema::hasColumn('tasks', 'no_deadline_ack')) {
                $table->dropColumn('no_deadline_ack');
            }
            if (Schema::hasColumn('tasks', 'owner_state')) {
                $table->dropColumn('owner_state');
            }
        });
    }
};
