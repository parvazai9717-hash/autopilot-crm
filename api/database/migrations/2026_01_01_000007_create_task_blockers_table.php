<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('task_blockers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->enum('reason_code', [
                'waiting_for_person',
                'waiting_for_info',
                'waiting_for_approval',
                'technical_issue',
                'unclear_requirement',
                'other'
            ]);
            $table->text('description')->nullable();
            $table->foreignId('blocked_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['task_id', 'resolved_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_blockers');
    }
};
