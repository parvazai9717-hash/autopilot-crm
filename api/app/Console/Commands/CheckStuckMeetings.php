<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use Illuminate\Console\Command;

class CheckStuckMeetings extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'meetings:check-stuck';

    /**
     * The console command description.
     */
    protected $description = 'Find and mark stuck processing meetings as failed if they exceed the timeout threshold.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $timeoutMinutes = (int) config('autopilot.meeting_processing_timeout', 30);
        $threshold = now()->subMinutes($timeoutMinutes);

        $stuckMeetings = Meeting::withoutGlobalScopes()
            ->where('status', 'processing')
            ->whereNotNull('processing_started_at')
            ->where('processing_started_at', '<', $threshold)
            ->get();

        $count = $stuckMeetings->count();

        foreach ($stuckMeetings as $meeting) {
            $meeting->update([
                'status' => 'failed',
                'error_message' => "Meeting processing timed out after {$timeoutMinutes} minutes.",
            ]);
        }

        $this->info("Checked for stuck meetings: {$count} marked as failed.");

        return Command::SUCCESS;
    }
}
