<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PromoteAdminCommand extends Command
{
    protected $signature = 'plnr:promote-admin {email : The user email to promote}';

    protected $description = 'Promote a user to the admin role';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email [{$email}].");

            return self::FAILURE;
        }

        $user->forceFill(['role' => User::ROLE_ADMIN])->save();
        $this->info("Promoted [{$email}] to admin.");

        return self::SUCCESS;
    }
}
