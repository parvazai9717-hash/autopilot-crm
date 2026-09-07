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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained('meetings')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('owner_name_raw')->nullable();
            $table->boolean('owner_ambiguous')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('priority', ['high', 'medium', 'low'])->default('medium');
            $table->enum('status', [
                'detected',
                'pending_approval',
                'approved',
                'assigned',
                'in_progress',
                'completed',
                'rejected',
                'blocked',
                'overdue',
                'escalated'
            ])->default('detected');
            $table->string('previous_status')->nullable();
            $table->date('due_date')->nullable();
            $table->string('deadline_phrase')->nullable();
            $table->text('source_text')->nullable();
            $table->boolean('conditional')->default(false);

            $table->decimal('owner_confidence', 3, 2)->nullable();
            $table->decimal('deadline_confidence', 3, 2)->nullable();
            $table->decimal('action_confidence', 3, 2)->nullable();

            $table->date('last_reminder_at')->nullable();
            $table->integer('escalation_level')->default(0);

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Morning query optimization index required by specification
            $table->index(['org_id', 'status', 'due_date']);
            $table->index(['org_id', 'owner_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
