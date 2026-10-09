<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Weekend\DeliverWeekendPicks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWeekendPicksCommand extends Command
{
    protected $signature = 'weekends:send {--email= : Send only this account}';

    protected $description = 'Email and push the coming weekend picks to Pro accounts';

    public function handle(DeliverWeekendPicks $delivery): int
    {
        $email = trim((string) $this->option('email'));
        $sent = 0;
        $skipped = 0;

        $users = User::query()
            ->when($email !== '', fn ($query) => $query->whereRaw('LOWER(email) = ?', [strtolower($email)]))
            ->orderBy('id')
            ->get();

        foreach ($users as $user) {
            try {
                $result = $delivery->deliver($user);

                if ($result === DeliverWeekendPicks::SENT) {
                    $sent++;

                    continue;
                }

                $skipped++;
            } catch (Throwable $exception) {
                $skipped++;
                Log::warning('weekend.send_failed', [
                    'user_id' => $user->id,
                    'message' => $exception->getMessage(),
                ]);
                $this->warn($user->email.': '.$exception->getMessage());
            }
        }

        $this->info("Sent weekend picks to {$sent} accounts. Skipped {$skipped}.");

        return self::SUCCESS;
    }
}
