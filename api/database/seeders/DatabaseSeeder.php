<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * DatabaseSeeder is production-safe: it only calls seeders that create
     * immutable system/config data (organizations, roles, etc.).
     *
     * Demo data (users with password123, sample meetings, sample tasks) lives
     * in DemoSeeder, which is guarded to local/testing only. Running
     * `php artisan db:seed` in production will NOT create demo accounts.
     *
     * To seed locally: php artisan db:seed
     * To seed demo only: php artisan db:seed --class=DemoSeeder
     */
    public function run(): void
    {
        // Production-safe: creates the organization record.
        $this->call([
            OrganizationSeeder::class,
        ]);

        // Demo data: guarded — never runs in production.
        if (app()->environment(['local', 'testing'])) {
            $this->call([
                DemoSeeder::class,
            ]);
        }
    }
}
