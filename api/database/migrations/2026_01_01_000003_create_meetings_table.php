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
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('title');
            $table->date('meeting_date');
            $table->string('timezone')->default('Asia/Karachi');
            $table->enum('source', [
                'upload', 'text', 'zoom', 'google_meet', 'teams', 'whatsapp', 'bot'
            ])->default('upload');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('audio_path')->nullable();
            $table->unsignedInteger('audio_duration_seconds')->nullable();
            $table->longText('transcript')->nullable();
            $table->text('summary')->nullable();
            $table->enum('status', [
                'uploaded', 'processing', 'extracted', 'reviewed', 'failed'
            ])->default('uploaded');
            $table->text('error_message')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
