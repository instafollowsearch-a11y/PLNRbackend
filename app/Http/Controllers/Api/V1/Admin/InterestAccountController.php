<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\InterestScan;
use App\Services\Interests\InterestAccountDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterestAccountController extends Controller
{
    public function index(Request $request, InterestAccountDirectory $directory): JsonResponse
    {
        $status = $request->string('status')->toString();

        if (! in_array($status, ['all', 'matched', 'unmatched', 'skipped'], true)) {
            $status = 'all';
        }

        $page = $directory->page(
            trim($request->string('search')->toString()),
            $status,
            max(1, $request->integer('page', 1)),
            min(100, max(1, $request->integer('per_page', 20))),
        );

        return response()->json([
            'data' => [
                'scan' => $this->scanPayload($this->latestScan()),
                'summary' => $page['summary'],
                'accounts' => $page['accounts'],
                'meta' => $page['meta'],
            ],
            'message' => 'Interest accounts retrieved.',
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
