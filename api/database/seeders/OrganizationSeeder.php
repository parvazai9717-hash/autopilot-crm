<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Organization::updateOrCreate(
            ['id' => 1],
            [
                'name' => 'Demo Company',
                'timezone' => 'Asia/Karachi',
                'settings' => [
                    'reminder_windows_days' => [
                        'high' => [3, 2, 1, 0],
                        'medium' => [2, 1, 0],
                        'low' => [1, 0],
                    ],
                    'escalation_days_overdue' => [
                        'high' => ['manager' => 2, 'executive' => 5],
                        'medium' => ['manager' => 4, 'executive' => 8],
                        'low' => ['manager' => 7, 'executive' => 14],
                    ],
                    'working_hours' => [
                        'start' => '09:00',
                        'end' => '18:00',
                    ],
                    'working_days' => [1, 2, 3, 4, 5],
                    'notification_channels' => ['email'],
                ],
            ]
        );
    }
}
