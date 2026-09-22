<?php

namespace App\Console\Commands;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Console\Command;

class SecurityForceReset extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:force-reset
                            {--all : Force password reset for all active users}
                            {--user= : Target user by ID or email}
                            {--org= : Target all users in organization ID}
                            {--dry-run : Simulate action without updating records or revoking tokens}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Force users to reset their passwords on next login and revoke all active tokens/sessions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $all = (bool) $this->option('all');
        $userOption = $this->option('user');
        $orgOption = $this->option('org');
        $dryRun = (bool) $this->option('dry-run');

        if (!$all && empty($userOption) && empty($orgOption)) {
            $this->error('Target required. Specify --all, --user=<id|email>, or --org=<id>.');
            return Command::FAILURE;
        }

        $query = User::withoutGlobalScopes();

        if ($orgOption) {
            $query->where('org_id', (int) $orgOption);
        }

        if ($userOption) {
            if (is_numeric($userOption)) {
                $query->where('id', (int) $userOption);
            } else {
                $query->where('email', (string) $userOption);
            }
        }

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->warn('No users matched the specified criteria.');
            return Command::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d user(s)...',
            $dryRun ? '[DRY-RUN] Would force reset' : 'Force resetting',
            $users->count()
        ));

        $rows = [];

        foreach ($users as $user) {
            if (!$dryRun) {
                $user->update([
                    'must_change_password' => true,
                ]);

                // Revoke all personal access tokens
                $user->tokens()->delete();

                // Record audit event
                LoginEvent::record(
                    'force_reset',
                    $user->email,
                    $user->id,
                    $user->org_id,
                    null,
                    ['command' => 'security:force-reset']
                );
            }

            $rows[] = [
                $user->id,
                $user->email,
                $user->org_id,
                $dryRun ? 'WOULD_RESET' : 'RESET_TRIGGERED',
            ];
        }

        $this->table(['ID', 'Email', 'Org ID', 'Action'], $rows);

        $this->info(sprintf(
            '%s Successfully processed %d user(s).',
            $dryRun ? '[DRY-RUN]' : '✓',
            $users->count()
        ));

        return Command::SUCCESS;
    }
}
