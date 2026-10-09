<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RefinePlanSessionRequest;
use App\Http\Requests\Api\V1\SelectSuggestionRequest;
use App\Http\Requests\Api\V1\SendItineraryEmailRequest;
use App\Http\Requests\Api\V1\StorePlanSessionRequest;
use App\Http\Resources\ItineraryResource;
use App\Http\Resources\PlanSessionResource;
use App\Http\Resources\SuggestionResource;
use App\Mail\ItineraryMail;
use App\Models\PlanSession;
use App\Models\PlanType;
use App\Models\Suggestion;
use App\Services\AI\ItineraryService;
use App\Services\AI\SuggestionService;
use App\Services\Notifications\ExpoPushService;
use App\Services\Plans\PlanQuotaService;
use App\Services\Reminders\ScheduleItineraryStopReminders;
use App\Support\Http\RunAfterResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class PlanSessionController extends Controller
{
    public function __construct(
        private readonly ScheduleItineraryStopReminders $scheduleStopReminders,
        private readonly PlanQuotaService $planQuota,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, $request->integer('per_page', 20)));
        $user = $request->user();
        $memberSessionIds = $user->planMemberships()->pluck('plan_session_id');

        $paginator = PlanSession::query()
            ->where(function ($query) use ($user, $memberSessionIds): void {
                $query->where('user_id', $user->id)
                    ->orWhereIn('id', $memberSessionIds);
            })
            ->with(['planType', 'itinerary', 'user'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => [
                'plan_sessions' => PlanSessionResource::collection($paginator->items())->resolve(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'message' => 'Plan sessions retrieved.',
        ]);
    }

    public function store(StorePlanSessionRequest $request): JsonResponse
    {
        $this->planQuota->assertCanCreate($request);

        $planType = PlanType::query()->where('slug', $request->string('plan_type'))->firstOrFail();

        $session = PlanSession::query()->create([
            'user_id' => $request->user()?->id,
            'creator_ip' => $request->ip(),
            'plan_type_id' => $planType->id,
            'status' => PlanSession::STATUS_READY,
            'city' => $request->resolvedCity(),
            'answers' => $request->input('answers'),
        ]);

        $session->load('planType');

        return response()->json([
            'data' => [
                'plan_session' => new PlanSessionResource($session),
            ],
            'message' => 'Plan session created.',
        ], 201);
    }

    public function claim(Request $request, PlanSession $planSession): JsonResponse
    {
        $user = $request->user();

        if ($planSession->user_id !== null && $planSession->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'session' => ['This plan belongs to another account.'],
            ]);
        }

        if ($planSession->user_id === null) {
            $planSession->update(['user_id' => $user->id]);
            $planSession->ensureOwnerMembership();
        }

        $planSession->load(['planType', 'suggestions', 'itinerary', 'user']);

        return response()->json([
            'data' => [
                'plan_session' => new PlanSessionResource($planSession),
            ],
            'message' => 'Plan session claimed.',
        ]);
    }

    public function show(PlanSession $planSession): JsonResponse
    {
        $this->authorize('view', $planSession);

        $planSession->load(['planType', 'suggestions', 'itinerary', 'user']);

        return response()->json([
            'data' => [
                'plan_session' => new PlanSessionResource($planSession),
            ],
            'message' => 'Plan session retrieved.',
        ]);
    }

    public function suggestions(PlanSession $planSession, SuggestionService $suggestionService): JsonResponse
    {
        $this->authorize('update', $planSession);

        if ($busy = $this->alreadyGenerating($planSession, PlanSession::GENERATION_SUGGESTIONS)) {
            return $busy;
        }

        $planSession->update([
            'generation_status' => PlanSession::GENERATION_SUGGESTIONS,
            'generation_error' => null,
        ]);

        try {
            $accepted = RunAfterResponse::defer(function () use ($planSession, $suggestionService): void {
                try {
                    $suggestionService->generate($planSession->fresh() ?? $planSession);
                    $planSession->update([
                        'generation_status' => null,
                        'generation_error' => null,
                    ]);
                    $this->notifyReady($planSession, 'Your ideas are ready', 'Open PLNR to pick a plan.');
                } catch (Throwable $exception) {
                    $this->markGenerationFailed($planSession, 'plan_session.suggestions_failed', $exception, 'Unable to generate suggestions. Please try again.');

                    if (RunAfterResponse::inline()) {
                        throw $exception;
                    }
                }
            });
        } catch (Throwable) {
            return response()->json([
                'message' => 'Unable to generate suggestions. Please try again.',
            ], 502);
        }

        if ($accepted instanceof JsonResponse) {
            return $accepted;
        }

        $planSession->refresh()->load(['planType', 'suggestions']);

        if ($planSession->generation_status === PlanSession::GENERATION_FAILED) {
            return response()->json([
                'message' => $planSession->generation_error ?: 'Unable to generate suggestions. Please try again.',
            ], 502);
        }

        return response()->json([
            'data' => [
                'plan_session' => new PlanSessionResource($planSession),
                'suggestions' => SuggestionResource::collection($planSession->suggestions),
            ],
            'message' => 'Suggestions generated.',
        ]);
    }

    public function refine(
        RefinePlanSessionRequest $request,
        PlanSession $planSession,
        SuggestionService $suggestionService,
    ): JsonResponse {
        $this->authorize('update', $planSession);

        if ($planSession->refinementCount() >= PlanSession::MAX_REFINEMENTS) {
            return response()->json([
                'message' => 'Maximum refinement limit reached.',
            ], 422);
        }

        if ($busy = $this->alreadyGenerating($planSession, PlanSession::GENERATION_REFINE)) {
            return $busy;
        }

        $planSession->addRefinementMessage($request->string('message')->toString());
        $planSession->update([
            'generation_status' => PlanSession::GENERATION_REFINE,
            'generation_error' => null,
        ]);

        try {
            $accepted = RunAfterResponse::defer(function () use ($planSession, $suggestionService): void {
                try {
                    $suggestionService->generate($planSession->fresh() ?? $planSession);
                    $planSession->update([
                        'generation_status' => null,
                        'generation_error' => null,
                    ]);
                    $this->notifyReady($planSession, 'Your ideas are ready', 'Open PLNR to pick a plan.');
                } catch (Throwable $exception) {
                    $planSession->removeLastRefinementMessage();
                    $this->markGenerationFailed($planSession, 'plan_session.refine_failed', $exception, 'Unable to refine suggestions. Please try again.');

                    if (RunAfterResponse::inline()) {
                        throw $exception;
                    }
                }
            });
        } catch (Throwable) {
            return response()->json([
                'message' => 'Unable to refine suggestions. Please try again.',
            ], 502);
        }

        if ($accepted instanceof JsonResponse) {
            return $accepted;
        }

        $planSession->refresh()->load(['planType', 'suggestions']);

        return response()->json([
            'data' => [
                'plan_session' => new PlanSessionResource($planSession),
                'suggestions' => SuggestionResource::collection($planSession->suggestions),
            ],
            'message' => 'Suggestions refined.',
        ]);
    }

    public function select(SelectSuggestionRequest $request, PlanSession $planSession): JsonResponse
    {
        $this->authorize('update', $planSession);

        $suggestion = Suggestion::query()
            ->where('plan_session_id', $planSession->id)
            ->whereKey($request->integer('suggestion_id'))
            ->firstOrFail();

        $planSession->suggestions()->update(['selected_at' => null]);
        $suggestion->update(['selected_at' => now()]);

        $planSession->markStatus(PlanSession::STATUS_SELECTED);
        $planSession->load(['planType', 'suggestions']);

        return response()->json([
            'data' => [
                'plan_session' => new PlanSessionResource($planSession),
                'selected_suggestion' => new SuggestionResource($suggestion->fresh()),
            ],
            'message' => 'Suggestion selected.',
        ]);
    }

    public function draftPlan(PlanSession $planSession, Suggestion $suggestion, ItineraryService $itineraryService): JsonResponse
    {
        $this->authorize('update', $planSession);

        if ($suggestion->plan_session_id !== $planSession->id) {
            abort(404);
        }

        if ($busy = $this->alreadyGenerating($planSession, PlanSession::GENERATION_DRAFT)) {
            return $busy;
        }

        $planSession->update([
            'generation_status' => PlanSession::GENERATION_DRAFT,
            'generation_error' => null,
        ]);

        try {
            $accepted = RunAfterResponse::defer(function () use ($planSession, $suggestion, $itineraryService): void {
                try {
                    $itineraryService->draft($planSession->fresh() ?? $planSession, $suggestion);
                    $planSession->update([
                        'generation_status' => null,
                        'generation_error' => null,
                    ]);
                } catch (Throwable $exception) {
                    $this->markGenerationFailed($planSession, 'plan_session.draft_plan_failed', $exception, 'Unable to write this plan. Please try again.');

                    if (RunAfterResponse::inline()) {
                        throw $exception;
                    }
                }
            });
        } catch (Throwable) {
            return response()->json([
                'message' => 'Unable to write this plan. Please try again.',
            ], 502);
        }

        if ($accepted instanceof JsonResponse) {
            return $accepted;
        }

        $suggestion = $suggestion->fresh() ?? $suggestion;

        return response()->json([
            'data' => [
                'suggestion' => new SuggestionResource($suggestion),
            ],
            'message' => 'Detailed plan ready.',
        ]);
    }

    public function itinerary(PlanSession $planSession, ItineraryService $itineraryService): JsonResponse
    {
        $this->authorize('update', $planSession);

        $selected = $planSession->selectedSuggestion();

        if ($selected === null) {
            return response()->json([
                'message' => 'Select a suggestion before generating an itinerary.',
            ], 422);
        }

        if ($planSession->itinerary()->exists() && $planSession->generation_status !== PlanSession::GENERATION_ITINERARY) {
            $planSession->load(['planType', 'suggestions', 'itinerary']);

            return response()->json([
                'data' => [
                    'plan_session' => new PlanSessionResource($planSession),
                    'itinerary' => new ItineraryResource($planSession->itinerary),
                ],
                'message' => 'Itinerary generated.',
            ]);
        }

        if ($busy = $this->alreadyGenerating($planSession, PlanSession::GENERATION_ITINERARY)) {
            return $busy;
        }

        $planSession->update([
            'generation_status' => PlanSession::GENERATION_ITINERARY,
            'generation_error' => null,
        ]);

        try {
            $accepted = RunAfterResponse::defer(function () use ($planSession, $selected, $itineraryService): void {
                try {
                    $itineraryService->generate($planSession->fresh() ?? $planSession, $selected);
                    $planSession->update([
                        'generation_status' => null,
                        'generation_error' => null,
                    ]);
                    $this->notifyReady($planSession, 'Your plan is ready', 'The full plan is waiting in PLNR.');
                } catch (Throwable $exception) {
                    $this->markGenerationFailed($planSession, 'plan_session.itinerary_failed', $exception, 'Unable to generate itinerary. Please try again.');

                    if (RunAfterResponse::inline()) {
                        throw $exception;
                    }
                }
            });
        } catch (Throwable) {
            return response()->json([
                'message' => 'Unable to generate itinerary. Please try again.',
            ], 502);
        }

        if ($accepted instanceof JsonResponse) {
            return $accepted;
        }

        $planSession->refresh()->load(['planType', 'suggestions', 'itinerary']);

        if ($planSession->generation_status === PlanSession::GENERATION_FAILED || $planSession->itinerary === null) {
            return response()->json([
                'message' => $planSession->generation_error ?: 'Unable to generate itinerary. Please try again.',
            ], 502);
        }

        return response()->json([
            'data' => [
                'plan_session' => new PlanSessionResource($planSession),
                'itinerary' => new ItineraryResource($planSession->itinerary),
            ],
            'message' => 'Itinerary generated.',
        ]);
    }

    public function sendEmail(SendItineraryEmailRequest $request, PlanSession $planSession): JsonResponse
    {
        $this->authorize('update', $planSession);

        $planSession->loadMissing(['itinerary', 'planType']);

        $itinerary = $planSession->itinerary;

        if ($itinerary === null) {
            return response()->json([
                'message' => 'Generate an itinerary before sending email.',
            ], 422);
        }

        $email = $request->string('email')->toString();

        try {
            Mail::to($email)->send(new ItineraryMail($planSession, $itinerary));
        } catch (Throwable $exception) {
            Log::error('plan_session.send_email_failed', [
                'plan_session_uuid' => $planSession->uuid,
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to send itinerary email. Please try again.',
            ], 502);
        }

        $itinerary->update(['email_sent_at' => now()]);
        $planSession->update([
            'recipient_email' => $email,
        ]);
        $planSession->markStatus(PlanSession::STATUS_COMPLETED);

        $this->scheduleStopReminders->forGuestSend($planSession, $itinerary, $email);

        $planSession->loadMissing(['members.user', 'user']);
        $memberEmails = $planSession->members
            ->map(fn ($member) => $member->user?->email)
            ->filter()
            ->push($planSession->user?->email)
            ->map(fn ($memberEmail) => strtolower((string) $memberEmail))
            ->unique()
            ->reject(fn ($memberEmail) => $memberEmail === strtolower($email))
            ->values();

        foreach ($memberEmails as $memberEmail) {
            $this->scheduleStopReminders->forMember($planSession, $itinerary, $memberEmail);
        }

        return response()->json([
            'data' => [
                'email' => $email,
                'sent_at' => $itinerary->fresh()->email_sent_at?->toIso8601String(),
            ],
            'message' => 'Itinerary email sent.',
        ]);
    }

    private function alreadyGenerating(PlanSession $planSession, string $status): ?JsonResponse
    {
        if ($planSession->generation_status !== $status) {
            return null;
        }

        // A crashed request can leave the flag set. Let a new attempt start.
        if ($planSession->updated_at !== null && $planSession->updated_at->lt(now()->subMinutes(4))) {
            return null;
        }

        return response()->json([
            'data' => [
                'status' => 'generating',
            ],
            'message' => 'Still working.',
        ], 202);
    }

    private function markGenerationFailed(PlanSession $planSession, string $logKey, Throwable $exception, string $message): void
    {
        Log::warning($logKey, [
            'plan_session_uuid' => $planSession->uuid,
            'error' => $exception->getMessage(),
        ]);

        $planSession->update([
            'generation_status' => PlanSession::GENERATION_FAILED,
            'generation_error' => $message,
        ]);
    }

    private function notifyReady(PlanSession $planSession, string $title, string $body): void
    {
        $planSession->loadMissing(['user', 'planType']);
        $user = $planSession->user;

        if ($user === null) {
            return;
        }

        app(ExpoPushService::class)->sendToUser($user, $title, $body, [
            'screen' => 'plan',
            'plan_type_slug' => $planSession->planType?->slug,
            'plan_session_uuid' => $planSession->uuid,
        ]);
    }
}
