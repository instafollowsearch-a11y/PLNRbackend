<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Places\PlaceListingLookup;
use App\Services\Settings\AppSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class PlacePhotoController extends Controller
{
    public function show(string $token, PlaceListingLookup $listings, AppSettings $settings): Response
    {
        $name = $listings->photoName($token);
        $key = $settings->googlePlacesApiKey();

        if ($name === null || $key === null) {
            abort(404);
        }

        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => $key,
            ])->timeout(8)->get('https://places.googleapis.com/v1/'.$name.'/media', [
                'maxWidthPx' => 800,
            ]);
        } catch (ConnectionException|Throwable) {
            abort(404);
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        if (! $response->successful() || ! str_starts_with($type, 'image/')) {
            abort(404);
        }

        return response($response->body(), 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
