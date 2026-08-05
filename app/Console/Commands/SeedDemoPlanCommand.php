<?php

namespace App\Console\Commands;

use App\Models\Itinerary;
use App\Models\PlanSession;
use App\Models\PlanShare;
use App\Models\PlanType;
use App\Models\User;
use Illuminate\Console\Command;

class SeedDemoPlanCommand extends Command
{
    protected $signature = 'plnr:seed-demo-plan
        {email? : Owner email}
        {--reset-pro : Set owner pro_status inactive}
        {--activate-pro : Set owner pro_status active}
        {--share= : Create a pending share to this invitee email and print token}
        {--latest-share= : Print latest SHARE_TOKEN for invitee email (no seed)}';

    protected $description = 'Seed a completed night-out plan (and optional share) for QA / Playwright.';

    public function handle(): int
    {
        $latestShare = $this->option('latest-share');
        if (is_string($latestShare) && $latestShare !== '') {
            $token = PlanShare::query()
                ->where('invitee_email', strtolower(trim($latestShare)))
                ->latest('id')
                ->value('token');

            if (! is_string($token) || $token === '') {
                $this->error('No share found for '.$latestShare);

                return self::FAILURE;
            }

            $this->line('SHARE_TOKEN='.$token);

            return self::SUCCESS;
        }

        $email = (string) ($this->argument('email') ?? '');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error('User not found: '.$email);

            return self::FAILURE;
        }

        if ($this->option('reset-pro')) {
            $user->forceFill([
                'pro_status' => User::PRO_STATUS_INACTIVE,
                'stripe_subscription_id' => null,
                'pro_current_period_end' => null,
            ])->save();
        }

        if ($this->option('activate-pro')) {
            $user->forceFill([
                'pro_status' => User::PRO_STATUS_ACTIVE,
                'stripe_customer_id' => $user->stripe_customer_id ?: 'cus_qa_demo',
                'stripe_subscription_id' => $user->stripe_subscription_id ?: 'sub_qa_demo',
                'pro_current_period_end' => now()->addMonth(),
            ])->save();
        }

        $type = PlanType::query()->where('slug', 'night_out')->firstOrFail();
        $session = PlanSession::query()->create([
            'user_id' => $user->id,
            'plan_type_id' => $type->id,
            'status' => PlanSession::STATUS_COMPLETED,
            'city' => 'Austin',
            'answers' => [
                'city' => 'Austin',
                'interests' => 'live jazz',
                'group_size' => 2,
                'budget_per_person' => 80,
                'dates' => 'Saturday',
                'start_time' => '8:00 PM',
            ],
            'recipient_email' => $user->email,
        ]);
        $session->ensureOwnerMembership();

        Itinerary::query()->create([
            'plan_session_id' => $session->id,
            'email_sent_at' => now(),
            'content' => [
                'title' => 'Austin Jazz Night',
                'summary' => 'A low-key night out.',
                'stops' => [
                    ['time' => '8:00 PM', 'name' => 'Blue Note', 'activity' => 'Live jazz', 'notes' => 'Arrive early'],
                    ['time' => '10:30 PM', 'name' => 'Taco Joint', 'activity' => 'Late bites', 'notes' => 'Cash friendly'],
                ],
            ],
        ]);

        $this->line('PLAN_UUID='.$session->uuid);

        $shareEmail = $this->option('share');
        if (is_string($shareEmail) && $shareEmail !== '') {
            $share = PlanShare::query()->create([
                'plan_session_id' => $session->id,
                'inviter_user_id' => $user->id,
                'invitee_email' => strtolower(trim($shareEmail)),
                'status' => PlanShare::STATUS_PENDING,
                'expires_at' => now()->addDays(14),
            ]);
            $this->line('SHARE_TOKEN='.$share->token);
        }

        return self::SUCCESS;
    }
}
