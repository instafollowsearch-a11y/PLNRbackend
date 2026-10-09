<?php

namespace App\Services\Plans;

use App\Mail\PlanShareAcceptedMail;
use App\Mail\PlanShareInviteMail;
use App\Mail\SharedPlanItineraryMail;
use App\Models\PlanMember;
use App\Models\PlanSession;
use App\Models\PlanShare;
use App\Models\User;
use App\Services\Events\FindLocalCredit;
use App\Services\Events\FindLocalStopLinks;
use App\Services\Reminders\ScheduleItineraryStopReminders;
use App\Services\Settings\AppSettings;
use App\Services\Sms\SendsPlanInviteSms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PlanShareService
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly ScheduleItineraryStopReminders $scheduleStopReminders,
        private readonly SendsPlanInviteSms $inviteSms,
    ) {}

    public function createShare(PlanSession $planSession, User $inviter, string $inviteeEmail, ?string $inviteePhone = null): CreatedPlanShare
    {
        if (! $planSession->isOwnedBy($inviter)) {
            throw ValidationException::withMessages([
                'session' => ['Only the plan owner can share this plan.'],
            ]);
        }

        $inviteeEmail = strtolower(trim($inviteeEmail));
        if ($inviteeEmail === strtolower((string) $inviter->email)) {
            throw ValidationException::withMessages([
                'email' => ['You cannot share a plan with yourself.'],
            ]);
        }

        $planSession->ensureOwnerMembership();

        $share = PlanShare::query()->create([
            'plan_session_id' => $planSession->id,
            'inviter_user_id' => $inviter->id,
            'invitee_email' => $inviteeEmail,
            'invitee_phone' => $inviteePhone,
            'status' => PlanShare::STATUS_PENDING,
            'expires_at' => now()->addDays(14),
        ]);

        $share->load(['planSession.planType', 'inviter']);

        $urls = $this->inviteUrls($share);

        Mail::to($inviteeEmail)->send(new PlanShareInviteMail($share, $urls));

        [$smsSent, $smsNotice] = $this->sendInviteSms($share, $inviter, $urls);

        return new CreatedPlanShare($share, $smsSent, $smsNotice);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicPreview(PlanShare $share): array
    {
        $share->loadMissing(['planSession.planType', 'planSession.itinerary', 'inviter']);

        $accountExists = User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower($share->invitee_email)])
            ->exists();

        return [
            'token' => $share->token,
            'status' => $share->isPending() ? PlanShare::STATUS_PENDING : $share->status,
            'invitee_email' => $share->invitee_email,
            'account_exists' => $accountExists,
            'inviter_name' => $share->inviter?->name,
            'plan' => [
                'uuid' => $share->planSession?->uuid,
                'city' => $share->planSession?->city,
                'status' => $share->planSession?->status,
                'plan_type' => [
                    'slug' => $share->planSession?->planType?->slug,
                    'label' => $share->planSession?->planType?->label,
                ],
            ],
            'itinerary' => $this->itineraryPreview($share),
            'event_credits' => FindLocalCredit::forCity($share->planSession?->city),
            'urls' => $this->inviteUrls($share),
            'app_store_url' => $this->settings->appStoreUrl(),
            'play_store_url' => $this->settings->playStoreUrl(),
            'expires_at' => $share->expires_at?->toIso8601String(),
        ];
    }

    public function accept(PlanShare $share, User $user): PlanMember
    {
        if (! $share->isPending()) {
            throw ValidationException::withMessages([
                'share' => ['This invite is no longer available.'],
            ]);
        }

        if (strtolower($user->email) !== strtolower($share->invitee_email)) {
            throw ValidationException::withMessages([
                'email' => ['Sign in with the email this plan was shared to.'],
            ]);
        }

        return DB::transaction(function () use ($share, $user): PlanMember {
            $share->planSession?->ensureOwnerMembership();

            $member = PlanMember::query()->updateOrCreate(
                [
                    'plan_session_id' => $share->plan_session_id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => PlanMember::ROLE_VIEWER,
                    'plan_share_id' => $share->id,
                    'accepted_at' => now(),
                ],
            );

            $share->update([
                'status' => PlanShare::STATUS_ACCEPTED,
                'accepted_user_id' => $user->id,
                'accepted_at' => now(),
            ]);

            $share->load(['planSession.planType', 'planSession.itinerary', 'inviter']);

            if ($share->inviter?->email) {
                Mail::to($share->inviter->email)->send(new PlanShareAcceptedMail($share, $user));
            }

            $session = $share->planSession;
            if ($session?->itinerary !== null) {
                Mail::to($user->email)->send(new SharedPlanItineraryMail($session, $session->itinerary, $share->inviter));
                $this->scheduleStopReminders->forMember($session, $session->itinerary, $user->email);
            }

            return $member;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function itineraryPreview(PlanShare $share): ?array
    {
        $content = $share->planSession?->itinerary?->content;

        if (! is_array($content)) {
            return null;
        }

        $city = $share->planSession?->city;

        return app(FindLocalStopLinks::class)->attach($content, is_string($city) ? $city : null);
    }

    /**
     * @param  array{web: string|null, app: string}  $urls
     * @return array{0: bool, 1: string|null}
     */
    private function sendInviteSms(PlanShare $share, User $inviter, array $urls): array
    {
        $phone = $share->invitee_phone;

        if (! is_string($phone) || $phone === '') {
            return [false, null];
        }

        if (! $this->inviteSms->configured()) {
            return [false, 'Invite email sent. Text messaging is not set up.'];
        }

        $name = trim((string) $inviter->name);
        $who = $name !== '' ? $name : 'Someone';
        $url = $urls['web'] ?? $urls['app'];
        $body = "{$who} invited you to a plan on PLNR. View it: {$url} Reply STOP to opt out.";

        try {
            $this->inviteSms->send($phone, $body);

            return [true, null];
        } catch (\Throwable $exception) {
            Log::warning('Plan invite SMS failed.', [
                'share_id' => $share->id,
                'error' => $exception->getMessage(),
            ]);

            return [false, 'Invite email sent. The text could not be sent.'];
        }
    }

    /**
     * @return array{web: string|null, app: string}
     */
    private function inviteUrls(PlanShare $share): array
    {
        $webBase = rtrim((string) ($this->settings->webAppUrl() ?? ''), '/');

        return [
            'web' => $webBase !== '' ? $webBase.'/invite/'.$share->token : null,
            'app' => 'plnr://invite/'.$share->token,
        ];
    }
}
