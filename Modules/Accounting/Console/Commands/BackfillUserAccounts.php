<?php

namespace Modules\Accounting\Console\Commands;

use Illuminate\Console\Command;
use Modules\Accounting\Services\AccountService;
use Modules\Core\Entities\User;

class BackfillUserAccounts extends Command
{
    protected $signature = 'accounting:backfill-user-accounts';

    protected $description = 'Create a chart-of-accounts ledger account for every user who does not already have one.';

    public function handle(AccountService $accountService): int
    {
        $users = User::query()->whereDoesntHave('account')->get();

        if ($users->isEmpty()) {
            $this->info('All users already have a ledger account.');

            return self::SUCCESS;
        }

        $this->withProgressBar($users, function (User $user) use ($accountService) {
            $accountService->syncForRoles($user, $user->getRoleNames()->toArray());
        });

        $this->newLine(2);
        $this->info("Processed {$users->count()} user(s).");

        return self::SUCCESS;
    }
}
