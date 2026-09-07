<?php

namespace Database\Seeders;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\Task;
use App\Models\TaskEvent;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class MeetingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $timezone = 'Asia/Karachi';
        $today = Carbon::now($timezone)->startOfDay();

        // Target dates based on org timezone
        $tomorrow = $today->copy()->addDay()->format('Y-m-d');
        $friday = $today->copy()->next(Carbon::FRIDAY)->format('Y-m-d');
        $nextMonday = $today->copy()->next(Carbon::MONDAY)->format('Y-m-d');

        $transcript = "Ahmed, please prepare the ABC proposal by Friday. Sarah will contact the supplier tomorrow for pricing. We should probably redesign the website at some point. Ali will send me the financial report by next Monday.";

        $meeting = Meeting::withoutGlobalScopes()->updateOrCreate(
            ['id' => 1],
            [
                'org_id' => 1,
                'title' => 'Weekly Planning & Operations Meeting',
                'meeting_date' => $today->format('Y-m-d'),
                'timezone' => $timezone,
                'source' => 'text',
                'created_by' => 6, // Ahmad Ameen (admin)
                'transcript' => $transcript,
                'summary' => 'Discussion covered the ABC proposal preparation by Ahmed, supplier pricing outreach by Sarah, website redesign exploration, and financial report submission by Ali.',
                'status' => 'extracted',
            ]
        );

        // Participants
        $participants = [
            ['meeting_id' => 1, 'user_id' => 6, 'speaker_name' => 'Ahmad Ameen', 'attendance_status' => 'attended'],
            ['meeting_id' => 1, 'user_id' => 1, 'speaker_name' => 'Ahmed Raza', 'attendance_status' => 'attended'],
            ['meeting_id' => 1, 'user_id' => 2, 'speaker_name' => 'Sarah Khan', 'attendance_status' => 'attended'],
            ['meeting_id' => 1, 'user_id' => 3, 'speaker_name' => 'Ali Khan', 'attendance_status' => 'attended'],
            ['meeting_id' => 1, 'user_id' => 4, 'speaker_name' => 'Ali Raza', 'attendance_status' => 'attended'],
            ['meeting_id' => 1, 'user_id' => 5, 'speaker_name' => 'Bilal Sheikh', 'attendance_status' => 'attended'],
        ];

        MeetingParticipant::where('meeting_id', 1)->delete();
        foreach ($participants as $p) {
            MeetingParticipant::create($p);
        }

        // Extracted Tasks in pending_approval
        $tasks = [
            [
                'id' => 1,
                'org_id' => 1,
                'meeting_id' => 1,
                'title' => 'Prepare ABC proposal',
                'description' => 'Prepare the ABC proposal',
                'owner_id' => 1, // Ahmed Raza
                'owner_name_raw' => 'Ahmed Raza',
                'owner_ambiguous' => false,
                'created_by' => 6,
                'priority' => 'high',
                'status' => 'pending_approval',
                'due_date' => $friday,
                'deadline_phrase' => 'by Friday',
                'source_text' => 'Ahmed, please prepare the ABC proposal by Friday.',
                'conditional' => false,
                'action_confidence' => 1.00,
                'owner_confidence' => 1.00,
                'deadline_confidence' => 0.85,
            ],
            [
                'id' => 2,
                'org_id' => 1,
                'meeting_id' => 1,
                'title' => 'Contact supplier for pricing',
                'description' => 'Contact the supplier tomorrow for pricing',
                'owner_id' => 2, // Sarah Khan
                'owner_name_raw' => 'Sarah Khan',
                'owner_ambiguous' => false,
                'created_by' => 6,
                'priority' => 'medium',
                'status' => 'pending_approval',
                'due_date' => $tomorrow,
                'deadline_phrase' => 'tomorrow',
                'source_text' => 'Sarah will contact the supplier tomorrow for pricing.',
                'conditional' => false,
                'action_confidence' => 1.00,
                'owner_confidence' => 1.00,
                'deadline_confidence' => 0.90,
            ],
            [
                'id' => 3,
                'org_id' => 1,
                'meeting_id' => 1,
                'title' => 'Send financial report',
                'description' => 'Send financial report by next Monday',
                'owner_id' => null, // Ambiguous - approver must choose between Ali Khan and Ali Raza
                'owner_name_raw' => 'Ali',
                'owner_ambiguous' => true,
                'created_by' => 6,
                'priority' => 'medium',
                'status' => 'pending_approval',
                'due_date' => $nextMonday,
                'deadline_phrase' => 'by next Monday',
                'source_text' => 'Ali will send me the financial report by next Monday.',
                'conditional' => false,
                'action_confidence' => 1.00,
                'owner_confidence' => 0.50,
                'deadline_confidence' => 0.85,
            ],
        ];

        foreach ($tasks as $taskData) {
            $task = Task::withoutGlobalScopes()->updateOrCreate(
                ['id' => $taskData['id']],
                $taskData
            );

            // Audit log event
            TaskEvent::firstOrCreate(
                [
                    'task_id' => $task->id,
                    'event_type' => 'AI_DETECTED',
                ],
                [
                    'actor_id' => null,
                    'actor_type' => 'ai',
                    'metadata' => [
                        'source' => 'whisper_extraction',
                        'raw_owner' => $taskData['owner_name_raw'],
                        'confidence' => $taskData['action_confidence'],
                    ],
                    'created_at' => now(),
                ]
            );
        }
    }
}
