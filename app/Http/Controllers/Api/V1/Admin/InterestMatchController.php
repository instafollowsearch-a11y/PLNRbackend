<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\InterestMatchResource;
use App\Models\Event;
use App\Models\InterestMatch;
use App\Models\InterestScan;
use App\Services\Interests\ScanUserInterests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterestMatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, $request->integer('per_page', 20)));
        $query = InterestMatch::query()
            ->with(['user', 'event'])
            ->orderByDesc('score')
            ->orderBy(
                Event::query()
                    ->select('starts_at')
                    ->whereColumn('events.id', 'interest_matches.event_id'),
            );

        $search = trim($request->string('search')->toString());

        if ($search !== '') {
            $query->whereHas('user', function ($users) use ($search): void {
                $users->where('email', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => [
                'scan' => $this->scanPayload($this->latestScan()),
                'matches' => InterestMatchResource::collection($paginator->items())->resolve(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'message' => 'Interest matches retrieved.',
        ]);
    }

    public function store(ScanUserInterests $scanner): JsonResponse
    {
        $scan = $scanner->run();

        return response()->json([
            'data' => [
                'scan' => $this->scanPayload($scan),
            ],
            'message' => 'Interest scan finished.',
        ]);
    }

    private function latestScan(): ?InterestScan
    {
        return InterestScan::query()
            ->whereNotNull('finished_at')
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scanPayload(?InterestScan $scan): ?array
    {
        if ($scan === null) {
            return null;
        }

        return [
            'id' => $scan->id,
            'started_at' => $scan->started_at?->toIso8601String(),
            'finished_at' => $scan->finished_at?->toIso8601String(),
            'users_checked' => $scan->users_checked,
            'matches_kept' => $scan->matches_kept,
            'users_skipped' => $scan->users_skipped,
        ];
    }
}
