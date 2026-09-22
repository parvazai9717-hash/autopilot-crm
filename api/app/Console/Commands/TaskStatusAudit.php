<?php

namespace App\Console\Commands;

use App\Domain\Tasks\TaskStatus;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TaskStatusAudit extends Command
{
    /**
     * Phase 1: Pre-change audit for task statuses.
     *
     * Scans tasks table for:
     * - Stored 'overdue' or 'escalated' statuses (which must be migrated to 'assigned')
     * - Tasks marked 'completed' without completed_at timestamp
     * - Tasks with completed_at set but status is not 'completed'
     */
    protected $signature = 'tasks:status-audit
                            {--output= : Custom path for CSV output (default: storage/app/reports/task-status-audit.csv)}';

    protected $description = '[Phase 1] Audit task statuses and completed_at timestamps';

    public function handle(): int
    {
        $this->info('[tasks:status-audit] Scanning tasks for data anomalies...');

        $reportPath = $this->option('output') ?: storage_path('app/reports/task-status-audit.csv');
        $reportDir = dirname($reportPath);
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0755, true);
        }

        $anomalies = [];

        // 1. Check for stored 'overdue' or 'escalated'
        $invalidStoredStatus = DB::table('tasks')
            ->whereIn('status', ['overdue', 'escalated'])
            ->get(['id', 'org_id', 'title', 'status', 'due_date', 'completed_at']);

        foreach ($invalidStoredStatus as $row) {
            $anomalies[] = [
                'task_id'       => $row->id,
                'org_id'        => $row->org_id,
                'title'         => $row->title,
                'anomaly_type'  => 'INVALID_STORED_STATUS',
                'current_value' => $row->status,
                'recommended'   => 'assigned',
            ];
        }

        // 2. Completed without completed_at
        $completedWithoutTimestamp = DB::table('tasks')
            ->where('status', 'completed')
            ->whereNull('completed_at')
            ->get(['id', 'org_id', 'title', 'status', 'due_date', 'completed_at', 'updated_at']);

        foreach ($completedWithoutTimestamp as $row) {
            $anomalies[] = [
                'task_id'       => $row->id,
                'org_id'        => $row->org_id,
                'title'         => $row->title,
                'anomaly_type'  => 'COMPLETED_WITHOUT_TIMESTAMP',
                'current_value' => 'null',
                'recommended'   => $row->updated_at ?? 'now()',
            ];
        }

        // 3. Completed_at set but not completed status
        $timestampWithoutCompleted = DB::table('tasks')
            ->where('status', '!=', 'completed')
            ->whereNotNull('completed_at')
            ->get(['id', 'org_id', 'title', 'status', 'due_date', 'completed_at']);

        foreach ($timestampWithoutCompleted as $row) {
            $anomalies[] = [
                'task_id'       => $row->id,
                'org_id'        => $row->org_id,
                'title'         => $row->title,
                'anomaly_type'  => 'TIMESTAMP_ON_NON_COMPLETED',
                'current_value' => $row->completed_at,
                'recommended'   => 'null',
            ];
        }

        // Write CSV report
        $fp = fopen($reportPath, 'w');
        fputcsv($fp, ['task_id', 'org_id', 'title', 'anomaly_type', 'current_value', 'recommended']);
        foreach ($anomalies as $a) {
            fputcsv($fp, $a);
        }
        fclose($fp);

        $this->newLine();
        $this->table(
            ['Anomaly Type', 'Count'],
            [
                ['Stored "overdue" or "escalated"', $invalidStoredStatus->count()],
                ['Completed without timestamp', $completedWithoutTimestamp->count()],
                ['Timestamp on non-completed', $timestampWithoutCompleted->count()],
                ['Total anomalies found', count($anomalies)],
            ]
        );

        $this->info("Audit report written to: {$reportPath}");
        return 0;
    }
}
