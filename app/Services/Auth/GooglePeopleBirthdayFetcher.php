<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GooglePeopleBirthdayFetcher implements FetchesGoogleBirthday
{
    private const PEOPLE_URL = 'https://people.googleapis.com/v1/people/me';

    public function fetch(?string $accessToken): ?string
    {
        $token = is_string($accessToken) ? trim($accessToken) : '';

        if ($token === '') {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(8)
                ->get(self::PEOPLE_URL, [
                    'personFields' => 'birthdays',
                    // Profile source holds the Google Account birthday (with year).
                    'sources' => 'READ_SOURCE_TYPE_PROFILE',
                ]);
        } catch (\Throwable $exception) {
            Log::warning('Google People birthday request failed.', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Google People birthday request rejected.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $birthdays = $response->json('birthdays');

        if (! is_array($birthdays)) {
            return null;
        }

        return $this->pickFullDate($birthdays);
    }

    /**
     * @param  list<mixed>  $birthdays
     */
    private function pickFullDate(array $birthdays): ?string
    {
        $fallback = null;

        foreach ($birthdays as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $date = $entry['date'] ?? null;

            if (! is_array($date)) {
                continue;
            }

            $formatted = $this->formatDate($date);

            if ($formatted === null) {
                continue;
            }

            $metadata = is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [];
            $primary = ($metadata['primary'] ?? false) === true;
            $sourceType = is_array($metadata['source'] ?? null)
                ? (string) ($metadata['source']['type'] ?? '')
                : '';

            if ($primary || $sourceType === 'ACCOUNT') {
                return $formatted;
            }

            $fallback ??= $formatted;
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $date
     */
    private function formatDate(array $date): ?string
    {
        $year = $date['year'] ?? null;
        $month = $date['month'] ?? null;
        $day = $date['day'] ?? null;

        if (! is_numeric($year) || ! is_numeric($month) || ! is_numeric($day)) {
            return null;
        }

        $year = (int) $year;
        $month = (int) $month;
        $day = (int) $day;

        if ($year < 1900 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
