<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — additive migration.
 *
 * Adds composite index for task predicate queries:
 *   (org_id, owner_id, status, due_date)
 *
 * This accelerates employee dashboard and manager dashboard filters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['org_id', 'owner_id', 'status', 'due_date'], 'tasks_org_owner_status_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_org_owner_status_due_idx');
        });
    }
};
