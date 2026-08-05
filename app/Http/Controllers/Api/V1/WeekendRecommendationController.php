<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreWeekendRecommendationRequest;
use App\Http\Resources\WeekendRecommendationResource;
use App\Mail\WeekendRecommendationsMail;
use App\Models\WeekendRecommendation;
use App\Services\AI\WeekendRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;

class WeekendRecommendationController extends Controller
{
    public function __construct(
        private readonly WeekendRecommendationService $recommendations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $items = $request->user()
            ->weekendRecommendations()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => [
                'recommendations' => WeekendRecommendationResource::collection($items)->resolve(),
            ],
            'message' => 'Weekend recommendations retrieved.',
        ]);
    }

    public function store(StoreWeekendRecommendationRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $city = (string) ($request->validated('city') ?: $user->city);

        try {
            $recommendation = $this->recommendations->generate(
                $user,
                $city,
                $request->validated('interests'),
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => [
                'recommendation' => new WeekendRecommendationResource($recommendation),
            ],
            'message' => 'Weekend recommendations ready.',
        ], 201);
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

        return response()->json([
            'data' => [
                'recommendation' => new WeekendRecommendationResource($weekendRecommendation->fresh()),
            ],
            'message' => 'Weekend recommendations emailed.',
        ]);
    }

    private function authorizeRecommendation(Request $request, WeekendRecommendation $recommendation): void
    {
        if ($recommendation->user_id !== $request->user()->id) {
            abort(403, 'You do not have access to this recommendation.');
        }
    }
}
