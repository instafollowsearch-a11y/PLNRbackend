<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Places\PlaceListingLookup;
use App\Services\Settings\AppSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PlacePhotoController extends Controller
{
    public function show(string $token, PlaceListingLookup $listings, AppSettings $settings): Response
    {
        $name = $listings->photoName($token);
        $key = $settings->googleApiKey();

        if ($name === null || $key === null) {
            return $this->missing($name === null ? 'missing' : 'no-key');
        }

        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => $key,
                'Accept' => 'image/*',
            ])->timeout(8)->get('https://places.googleapis.com/v1/'.$name.'/media', [
                'maxWidthPx' => 800,
            ]);
        } catch (ConnectionException|Throwable) {
            return $this->missing('google');
        }

        $type = $this->mediaType($response->header('Content-Type'));

        if (! $response->successful() || ! str_starts_with($type, 'image/')) {
            $uri = $response->json('photoUri');

            if (is_string($uri) && str_starts_with($uri, 'https://')) {
                try {
                    $response = Http::withHeaders(['Accept' => 'image/*'])->timeout(8)->get($uri);
                } catch (ConnectionException|Throwable) {
                    return $this->missing('google-'.$response->status());
                }

                $type = $this->mediaType($response->header('Content-Type'));
            }
        }

        if (! $response->successful() || ! str_starts_with($type, 'image/')) {
            return $this->missing('google-'.$response->status());
        }

        return response($response->body(), 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function missing(string $reason): Response
    {
        return response()->json(['message' => ''], 404, [
            'X-Plnr-Photo' => $reason,
        ]);
    }

    private function mediaType(mixed $header): string
    {
        if (is_array($header)) {
            $header = $header[0] ?? '';
        }

        return strtolower(trim(explode(';', (string) $header)[0]));
    }
}
