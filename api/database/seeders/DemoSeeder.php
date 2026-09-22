<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * DemoSeeder — LOCAL / TESTING ONLY.
 *
 * Creates the demo users (password: password123) and sample meetings used
 * for local development and CI fixtures.
 *
 * This seeder is NEVER called in production. DatabaseSeeder guards it:
 *     if (app()->environment(['local', 'testing'])) { ... }
 *
 * If you accidentally run this in production, immediately run:
 *     php artisan security:lockdown-demo
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->error(
                'DemoSeeder must not run in ' . app()->environment() . '. Aborting.'
            );
            return;
        }

        $this->call([
            UserSeeder::class,
            MeetingSeeder::class,
        ]);
    }
}
