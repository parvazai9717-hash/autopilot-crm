<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultPassword = Hash::make('password123');

        // First pass: create users without manager foreign keys to prevent circular/ordering constraints
        $users = [
            [
                'id' => 1,
                'org_id' => 1,
                'name' => 'Ahmed Raza',
                'email' => 'ahmed@test.com',
                'role' => 'employee',
                'manager_id' => 5,
                'status' => 'active',
                'password' => $defaultPassword,
            ],
            [
                'id' => 2,
                'org_id' => 1,
                'name' => 'Sarah Khan',
                'email' => 'sarah@test.com',
                'role' => 'employee',
                'manager_id' => 5,
                'status' => 'active',
                'password' => $defaultPassword,
            ],
            [
                'id' => 3,
                'org_id' => 1,
                'name' => 'Ali Khan',
                'email' => 'ali.k@test.com',
                'role' => 'employee',
                'manager_id' => 5,
                'status' => 'active',
                'password' => $defaultPassword,
            ],
            [
                'id' => 4,
                'org_id' => 1,
                'name' => 'Ali Raza',
                'email' => 'ali.r@test.com',
                'role' => 'employee',
                'manager_id' => 5,
                'status' => 'active',
                'password' => $defaultPassword,
            ],
            [
                'id' => 5,
                'org_id' => 1,
                'name' => 'Bilal Sheikh',
                'email' => 'bilal@test.com',
                'role' => 'manager',
                'manager_id' => 7, // User 7 Imran Malik is Bilal Sheikh's manager
                'status' => 'active',
                'password' => $defaultPassword,
            ],
            [
                'id' => 6,
                'org_id' => 1,
                'name' => 'Ahmad Ameen',
                'email' => 'admin@test.com',
                'role' => 'admin',
                'manager_id' => null,
                'status' => 'active',
                'password' => $defaultPassword,
            ],
            [
                'id' => 7,
                'org_id' => 1,
                'name' => 'Imran Malik',
                'email' => 'exec@test.com',
                'role' => 'executive',
                'manager_id' => null,
                'status' => 'active',
                'password' => $defaultPassword,
            ],
        ];

        // Seed users with manager_id null first to respect foreign key constraint
        foreach ($users as $userData) {
            $dataWithoutManager = $userData;
            $dataWithoutManager['manager_id'] = null;

            User::withoutGlobalScopes()->updateOrCreate(
                ['id' => $userData['id']],
                $dataWithoutManager
            );
        }

        // Second pass: set the correct manager_id hierarchy
        foreach ($users as $userData) {
            if ($userData['manager_id'] !== null) {
                User::withoutGlobalScopes()
                    ->where('id', $userData['id'])
                    ->update(['manager_id' => $userData['manager_id']]);
            }
        }
    }
}
