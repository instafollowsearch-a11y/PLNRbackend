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
use App\Services\Reminders\ScheduleItineraryStopReminders;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;

class PlanSessionController extends Controller
{
    public function __construct(
        private readonly ScheduleItineraryStopReminders $scheduleStopReminders,
    ) {}

    public function store(StorePlanSessionRequest $request): JsonResponse
    {
        $planType = PlanType::query()->where('slug', $request->string('plan_type'))->firstOrFail();

        $session = PlanSession::query()->create([
            'user_id' => $request->user()?->id,
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

    public function show(PlanSession $planSession): JsonResponse
    {
        $this->authorize('view', $planSession);

        $planSession->load(['planType', 'suggestions', 'itinerary']);

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

        try {
            $suggestions = $suggestionService->generate($planSession);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            Log::warning('plan_session.suggestions_failed', [
                'plan_session_uuid' => $planSession->uuid,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to generate suggestions. Please try again.',
            ], 502);
        }

        $planSession->load(['planType', 'suggestions']);

        return response()->json([
            'data' => [
                'plan_session' => new PlanSessionResource($planSession),
                'suggestions' => SuggestionResource::collection($suggestions),
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

        $planSession->addRefinementMessage($request->string('message')->toString());

        try {
            $suggestions = $suggestionService->generate($planSession);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            Log::warning('plan_session.refine_failed', [
                'plan_session_uuid' => $planSession->uuid,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to refine suggestions. Please try again.',
            ], 502);
        }

        $planSession->load(['planType', 'suggestions']);

        return response()->json([
            'data' => [
                'plan_session' => new PlanSessionResource($planSession),
                'suggestions' => SuggestionResource::collection($suggestions),
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

    public function itinerary(PlanSession $planSession, ItineraryService $itineraryService): JsonResponse
    {
        $this->authorize('update', $planSession);

        $selected = $planSession->selectedSuggestion();

        if ($selected === null) {
            return response()->json([
                'message' => 'Select a suggestion before generating an itinerary.',
            ], 422);
        }

        try {
            $itinerary = $itineraryService->generate($planSession, $selected);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            Log::warning('plan_session.itinerary_failed', [
                'plan_session_uuid' => $planSession->uuid,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to generate itinerary. Please try again.',
            ], 502);
        }

        $planSession->load(['planType', 'suggestions', 'itinerary']);

        return response()->json([
            'data' => [
                'plan_session' => new PlanSessionResource($planSession),
                'itinerary' => new ItineraryResource($itinerary),
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
        $phone = $request->string('phone')->toString();

        try {
            Mail::to($email)->send(new ItineraryMail($planSession, $itinerary));
        } catch (\Throwable $exception) {
            Log::error('plan_session.send_email_failed', [
                'plan_session_uuid' => $planSession->uuid,
                'email' => $email,
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to send itinerary email. Please try again.',
            ], 502);
        }

        $itinerary->update(['email_sent_at' => now()]);
        $planSession->update([
            'recipient_phone' => $phone,
            'recipient_email' => $email,
        ]);
        $planSession->markStatus(PlanSession::STATUS_COMPLETED);

        $this->scheduleStopReminders->forGuestSend($planSession, $itinerary, $email);

        return response()->json([
            'data' => [
                'email' => $email,
                'phone' => $phone,
                'sent_at' => $itinerary->fresh()->email_sent_at?->toIso8601String(),
            ],
            'message' => 'Itinerary email sent.',
        ]);
    }
}
