<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StorePlanCardImageRequest;
use App\Services\Settings\AppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlanCardImageController extends Controller
{
    public function index(Request $request, AppSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => [
                'images' => $settings->planCardImages($request->getSchemeAndHttpHost()),
            ],
            'message' => 'Plan card images retrieved.',
        ]);
    }

    public function file(string $planType, AppSettings $settings): StreamedResponse
    {
        $path = AppSettings::isPlanCardType($planType)
            ? $settings->planCardFilePath($planType)
            : null;

        if ($path === null || ! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')->response($path, null, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function store(StorePlanCardImageRequest $request, AppSettings $settings): JsonResponse
    {
        $planType = (string) $request->input('plan_type');

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $extension = strtolower($file?->guessExtension() ?: 'jpg');
            $extension = $extension === 'jpeg' ? 'jpg' : $extension;
            $path = $file?->storeAs('plan-cards', $planType.'.'.$extension, 'public');

            if (! is_string($path) || $path === '') {
                abort(422, 'The image could not be saved.');
            }

            $settings->setPlanCardImageFile($planType, $path);
        } else {
            $settings->setPlanCardImageUrl($planType, trim((string) $request->input('url')));
        }

        return response()->json([
            'data' => [
                'images' => $settings->planCardImages($request->getSchemeAndHttpHost()),
            ],
            'message' => 'Plan card image updated.',
        ]);
    }

    public function destroy(string $planType, Request $request, AppSettings $settings): JsonResponse
    {
        if (! AppSettings::isPlanCardType($planType)) {
            abort(404);
        }

        $settings->clearPlanCardImage($planType);

        return response()->json([
            'data' => [
                'images' => $settings->planCardImages($request->getSchemeAndHttpHost()),
            ],
            'message' => 'Plan card image reset.',
        ]);
    }
}
