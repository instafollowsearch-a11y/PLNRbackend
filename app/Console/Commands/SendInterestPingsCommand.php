<?php

namespace App\Console\Commands;

use App\Models\InterestPing;
use App\Services\Notifications\ExpoPushService;
use App\Services\Pro\ProAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendInterestPingsCommand extends Command
{
    protected $signature = 'interests:send-pings';

    protected $description = 'Push interest pings that are due for Pro accounts';

    public function handle(ExpoPushService $push, ProAccess $proAccess): int
    {
        $pings = InterestPing::query()
            ->with(['event', 'user'])
            ->whereNull('push_sent_at')
            ->where('remind_at', '<=', now())
            ->where('remind_at', '>=', now()->subHours(6))
            ->orderBy('remind_at')
            ->get();

        $count = 0;

        foreach ($pings as $ping) {
            $user = $ping->user;

            if ($user === null || ! $proAccess->isActive($user)) {
                Log::info('reminder.push_skipped', [
                    'kind' => 'interest_ping',
                    'ping_id' => $ping->id,
                    'reason' => $user === null ? 'no_user' : 'not_pro',
                ]);

                continue;
            }

            if ($user->devicePushTokens()->doesntExist()) {
                Log::info('reminder.push_skipped', [
                    'kind' => 'interest_ping',
                    'ping_id' => $ping->id,
                    'reason' => 'no_tokens',
                ]);

                continue;
            }

            $title = trim((string) ($ping->event?->title ?? ''));
            if ($title === '') {
                $title = 'Something for you';
            }

            $interest = trim((string) $ping->matched_interest);
            $body = match ($ping->kind) {
                InterestPing::KIND_DAY_BEFORE => $interest !== ''
                    ? "Tomorrow — matches your interest: {$interest}"
                    : 'Coming up tomorrow on your interest list.',
                default => $interest !== ''
                    ? "Matches your interest: {$interest}"
                    : 'Did you know this is happening today?',
            };

            $sent = $push->sendToUser($user, $title, $body, [
                'screen' => 'interest_pings',
                'event_id' => $ping->event_id,
                'kind' => $ping->kind,
            ]);

            if ($sent) {
                $ping->update(['push_sent_at' => now()]);
                $count++;
            }
        }

        $this->info("Sent {$count} interest pings.");

        return self::SUCCESS;
    }
}
