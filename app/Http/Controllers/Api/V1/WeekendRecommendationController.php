<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreWeekendRecommendationRequest;
use App\Http\Resources\WeekendRecommendationResource;
use App\Mail\WeekendRecommendationsMail;
use App\Models\User;
use App\Models\WeekendRecommendation;
use App\Services\AI\WeekendRecommendationService;
use App\Services\Notifications\ExpoPushService;
use App\Services\Weekend\ScheduleWeekendPickNudges;
use App\Support\Http\RunAfterResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class WeekendRecommendationController extends Controller
{
    public function __construct(
        private readonly WeekendRecommendationService $recommendations,
        private readonly ScheduleWeekendPickNudges $nudges,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $items = $request->user()
            ->weekendRecommendations()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $userId = $request->user()->id;

        return response()->json([
            'data' => [
                'recommendations' => WeekendRecommendationResource::collection($items)->resolve(),
                'generation_status' => Cache::get($this->generationKey($userId)),
                'generation_error' => Cache::get($this->generationKey($userId).'.error'),
            ],
            'message' => 'Weekend recommendations retrieved.',
        ]);
    }

    public function store(StoreWeekendRecommendationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $city = (string) ($request->validated('city') ?: $user->city);
        $interests = array_values($request->validated('interests'));

        if ($city !== '' && $interests !== []) {
            $user->update([
                'city' => $city,
                'interests' => $interests,
            ]);
        }

        $cacheKey = $this->generationKey($user->id);

        if (Cache::get($cacheKey) === 'generating') {
            return response()->json([
                'data' => ['status' => 'generating'],
                'message' => 'Still working.',
            ], 202);
        }

        Cache::put($cacheKey, 'generating', now()->addMinutes(10));
        Cache::forget($cacheKey.'.error');

        try {
            $accepted = RunAfterResponse::defer(function () use ($user, $city, $interests, $cacheKey): void {
                try {
                    $recommendation = $this->recommendations->generate($user->fresh() ?? $user, $city, $interests);
                    Cache::forget($cacheKey);
                    Cache::forget($cacheKey.'.error');
                    app(ExpoPushService::class)->sendToUser($user, 'Your weekend picks', (string) $recommendation->city, [
                        'screen' => 'weekend',
                        'recommendation_uuid' => $recommendation->uuid,
                    ]);
                } catch (Throwable $exception) {
                    Cache::put($cacheKey, 'failed', now()->addMinutes(10));
                    Cache::put($cacheKey.'.error', $exception->getMessage(), now()->addMinutes(10));

                    if (RunAfterResponse::inline()) {
                        throw $exception;
                    }
                }
            });
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();

            return response()->json([
                'message' => is_string($message) ? $message : 'Unable to generate weekend picks.',
            ], 422);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        if ($accepted instanceof JsonResponse) {
            return $accepted;
        }

        $recommendation = $user->weekendRecommendations()->latest('id')->first();

        return response()->json([
            'data' => [
                'recommendation' => new WeekendRecommendationResource($recommendation),
            ],
            'message' => 'Weekend recommendations ready.',
        ], 201);
    }

    private function generationKey(int $userId): string
    {
        return 'weekend-generation.'.$userId;
    }

    public function show(Request $request, WeekendRecommendation $weekendRecommendation): JsonResponse
    {
        $this->authorizeRecommendation($request, $weekendRecommendation);

        return response()->json([
            'data' => [
                'recommendation' => new WeekendRecommendationResource($weekendRecommendation),
            ],
            'message' => 'Weekend recommendation retrieved.',
        ]);
    }

    public function sendEmail(Request $request, WeekendRecommendation $weekendRecommendation): JsonResponse
    {
        $this->authorizeRecommendation($request, $weekendRecommendation);

        Mail::to($request->user()->email)->send(new WeekendRecommendationsMail($weekendRecommendation));
        $weekendRecommendation->update(['email_sent_at' => now()]);
        $this->nudges->replaceFor($weekendRecommendation, (string) $request->user()->email);

        return response()->json([
            'data' => [
                'recommendation' => new WeekendRecommendationResource($weekendRecommendation->fresh()),
            ],
            'message' => 'Weekend recommendations emailed.',
        ]);
    }

    public function invite(Request $request, WeekendRecommendation $weekendRecommendation): JsonResponse
    {
        $this->authorizeRecommendation($request, $weekendRecommendation);

        $email = strtolower(trim((string) $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ])['email']));

        Mail::to($email)->send(new WeekendRecommendationsMail($weekendRecommendation));

        return response()->json([
            'data' => [
                'recommendation' => new WeekendRecommendationResource($weekendRecommendation),
            ],
            'message' => 'Invitation sent.',
        ]);
    }

    private function authorizeRecommendation(Request $request, WeekendRecommendation $recommendation): void
    {
        if ($recommendation->user_id !== $request->user()->id) {
            abort(403, 'You do not have access to this recommendation.');
        }
    }
}
