<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePlanShareRequest;
use App\Http\Resources\PlanSessionResource;
use App\Models\PlanSession;
use App\Models\PlanShare;
use App\Models\User;
use App\Services\Plans\PlanShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanShareController extends Controller
{
    public function __construct(
        private readonly PlanShareService $shares,
    ) {}

    public function store(StorePlanShareRequest $request, PlanSession $planSession): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $phone = $request->validated('phone');
        $created = $this->shares->createShare(
            $planSession,
            $user,
            (string) $request->validated('email'),
            is_string($phone) ? $phone : null,
        );
        $share = $created->share;
        $message = 'Plan invite sent.';

        if (is_string($phone) && $phone !== '' && ! $created->smsSent) {
            $message = $created->smsNotice ?? 'Invite email sent. The text could not be sent.';
        }

        return response()->json([
            'data' => [
                'share' => [
                    'token' => $share->token,
                    'invitee_email' => $share->invitee_email,
                    'invitee_phone' => $share->invitee_phone,
                    'status' => $share->status,
                    'expires_at' => $share->expires_at?->toIso8601String(),
                    'sms_sent' => $created->smsSent,
                ],
            ],
            'message' => $message,
        ], 201);
    }

    public function show(string $token): JsonResponse
    {
        $share = PlanShare::query()->where('token', $token)->firstOrFail();

        return response()->json([
            'data' => $this->shares->publicPreview($share),
            'message' => 'Plan invite retrieved.',
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $share = PlanShare::query()->where('token', $token)->firstOrFail();
        $member = $this->shares->accept($share, $request->user());

        $session = $share->planSession()->with(['planType', 'suggestions', 'itinerary'])->first();

        return response()->json([
            'data' => [
                'member' => [
                    'role' => $member->role,
                    'accepted_at' => $member->accepted_at?->toIso8601String(),
                ],
                'plan_session' => $session ? new PlanSessionResource($session) : null,
            ],
            'message' => 'You were added to the plan.',
        ]);
    }
}
