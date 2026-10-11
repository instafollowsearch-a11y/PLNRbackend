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
    private string $failure = 'google';

    public function show(string $token, PlaceListingLookup $listings, AppSettings $settings): Response
    {
        $name = $listings->photoName($token);
        $key = $settings->googleApiKey();

        if ($name === null || $key === null) {
            return $this->missing($name === null ? 'missing' : 'no-key');
        }

        $image = $this->download($name, $key);

        if ($image === null) {
            $fresh = $listings->replacePhotoName($token);

            if (is_string($fresh) && $fresh !== $name) {
                $image = $this->download($fresh, $key);
            }
        }

        if ($image === null) {
            return $this->missing($this->failure);
        }

        return response($image['body'], 200, [
            'Content-Type' => $image['type'],
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * @return array{body: string, type: string}|null
     */
    private function download(string $name, string $key): ?array
    {
        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => $key,
                'Accept' => 'image/*',
            ])->timeout(8)->get('https://places.googleapis.com/v1/'.$name.'/media', [
                'maxWidthPx' => 800,
            ]);
        } catch (ConnectionException|Throwable) {
            $this->failure = 'google';

            return null;
        }

        $type = $this->mediaType($response->header('Content-Type'));

        if (! $response->successful() || ! str_starts_with($type, 'image/')) {
            $uri = $response->json('photoUri');

            if (is_string($uri) && str_starts_with($uri, 'https://')) {
                try {
                    $response = Http::withHeaders(['Accept' => 'image/*'])->timeout(8)->get($uri);
                    $type = $this->mediaType($response->header('Content-Type'));
                } catch (ConnectionException|Throwable) {
                    $this->failure = $this->googleFailure($response);

                    return null;
                }
            }
        }

        if (! $response->successful() || ! str_starts_with($type, 'image/')) {
            $this->failure = $this->googleFailure($response);

            return null;
        }

        return [
            'body' => $response->body(),
            'type' => $type,
        ];
    }

    private function googleFailure(\Illuminate\Http\Client\Response $response): string
    {
        $message = $response->json('error.message');
        $message = is_string($message) ? trim(preg_replace('/AIza[0-9A-Za-z_-]+/', '', $message) ?? '') : '';
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');

        if ($message !== '') {
            return 'google-'.$response->status().' '.substr($message, 0, 80);
        }

        return 'google-'.$response->status();
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
