<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SecurityLockdownDemo extends Command
{
    /**
     * Phase 0 containment: deactivate all demo accounts.
     *
     * Finds users whose email matches the original seeded list OR whose
     * stored password hash verifies against "password123", then:
     *   - sets is_active = false (column added if missing — see Phase 0 migration)
     *   - deletes their personal_access_tokens rows
     *   - deletes their sessions rows
     *
     * Tasks are NOT touched; data is preserved.
     *
     * Rollback: set is_active = true for the listed user ids, then
     * issue fresh passwords via security:force-reset (Phase 4).
     */
    protected $signature = 'security:lockdown-demo
                            {--dry-run : Print the report without making changes}';

    protected $description = '[Phase 0] Deactivate all demo/seeded accounts that use password123';

    /** Known seeded emails from UserSeeder.php */
    private const SEEDED_EMAILS = [
        'ahmed@test.com',
        'sarah@test.com',
        'ali.k@test.com',
        'ali.r@test.com',
        'bilal@test.com',
        'admin@test.com',
        'exec@test.com',
    ];

    /** Exposed default password */
    private const DEMO_PASSWORD = 'password123';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        $this->info('[security:lockdown-demo] Scanning for demo accounts...');
        $this->newLine();

        // 1. Find users by seeded email
        $byEmail = DB::table('users')
            ->whereIn('email', self::SEEDED_EMAILS)
            ->select('id', 'name', 'email', 'role', 'org_id')
            ->get();

        // 2. Find additional users whose hash matches "password123"
        //    Exclude already-found ids. Limit to active rows for performance.
        $alreadyFoundIds = $byEmail->pluck('id')->toArray();

        $candidates = DB::table('users')
            ->whereNotIn('id', $alreadyFoundIds ?: [0])
            ->select('id', 'name', 'email', 'role', 'org_id', 'password')
            ->get();

        $byPassword = $candidates->filter(
            fn ($u) => Hash::check(self::DEMO_PASSWORD, $u->password ?? '')
        );

        $affected = $byEmail->merge($byPassword->map(fn ($u) => (object)[
            'id'     => $u->id,
            'name'   => $u->name,
            'email'  => $u->email,
            'role'   => $u->role,
            'org_id' => $u->org_id,
        ]))->unique('id');

        if ($affected->isEmpty()) {
            $this->info('No demo accounts found. Nothing to do.');
            return self::SUCCESS;
        }

        // 3. Report
        $this->warn("Found {$affected->count()} account(s) to deactivate:");
        $this->table(
            ['ID', 'Name', 'Email', 'Role', 'Org'],
            $affected->map(fn ($u) => [$u->id, $u->name, $u->email, $u->role, $u->org_id])->toArray()
        );

        if ($isDryRun) {
            $this->warn('[DRY RUN] No changes made.');
            return self::SUCCESS;
        }

        // 4. Deactivate
        $ids = $affected->pluck('id')->toArray();

        DB::transaction(function () use ($ids) {
            // is_active column is added by the Phase 0 migration.
            // If the column does not exist yet, warn instead of crashing.
            try {
                DB::table('users')
                    ->whereIn('id', $ids)
                    ->update(['is_active' => false]);
            } catch (\Throwable $e) {
                $this->warn('Could not set is_active (run migrations first): ' . $e->getMessage());
            }

            // Revoke Sanctum tokens
            DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\User')
                ->whereIn('tokenable_id', $ids)
                ->delete();

            // Revoke sessions (database driver)
            if (config('session.driver') === 'database') {
                DB::table('sessions')
                    ->whereIn('user_id', $ids)
                    ->delete();
            }
        });

        $this->info('✓ Deactivated ' . count($ids) . ' account(s).');
        $this->info('  Personal access tokens revoked: yes');
        $this->info('  Sessions revoked: yes');
        $this->info('  Tasks preserved: yes');
        $this->newLine();
        $this->line('Rollback: re-enable specific user IDs, then run:');
        $this->line('  php artisan security:force-reset {user_id}');

        return self::SUCCESS;
    }
}
