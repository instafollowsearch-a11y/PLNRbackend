<?php

namespace App\Services\AI;

use App\Models\PlanSession;

class PlanConsistency
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function cost(array $payload): ?float
    {
        foreach (['estimated_cost_per_person', 'estimated_cost', 'estimated_cost_total'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return (float) $payload[$key];
            }
        }

        $gas = $payload['estimated_gas_cost'] ?? null;
        $food = $payload['estimated_food_cost'] ?? null;

        if (is_numeric($gas) || is_numeric($food)) {
            return (float) ($gas ?? 0) + (float) ($food ?? 0);
        }

        return null;
    }

    /**
     * The price used to judge a cheaper request. A low number does not win when the text names a higher one.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function askingPrice(array $payload): ?float
    {
        $cost = self::cost($payload);
        $advertised = self::advertisedCost($payload);

        if ($cost === null) {
            return $advertised;
        }

        if ($advertised === null) {
            return $cost;
        }

        return max($cost, $advertised);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public static function placeNames(array $payload): array
    {
        $names = [];

        foreach (['venues', 'highlights'] as $key) {
            foreach ($payload[$key] ?? [] as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $names[] = trim($value);

                    continue;
                }

                if (! is_array($value)) {
                    continue;
                }

                $name = $value['name'] ?? null;

                if (is_string($name) && trim($name) !== '') {
                    $names[] = trim($name);
                }
            }
        }

        foreach ($payload['stops'] ?? [] as $stop) {
            if (! is_array($stop)) {
                continue;
            }

            $name = $stop['name'] ?? null;

            if (is_string($name) && trim($name) !== '') {
                $names[] = trim($name);
            }
        }

        return $names;
    }

    public static function wantsCheaper(PlanSession $session): bool
    {
        return (bool) preg_match('/\bcheaper\b/i', self::latestNote($session));
    }

    public static function wantsLowKey(PlanSession $session): bool
    {
        return ! self::wantsCheaper($session)
            && (bool) preg_match('/\blow[-\s]?key\b/i', self::latestNote($session));
    }

    public static function wantsDifferent(PlanSession $session): bool
    {
        return ! self::wantsCheaper($session)
            && ! self::wantsLowKey($session)
            && (bool) preg_match('/\bdifferent\b/i', self::latestNote($session));
    }

    public static function cheaperInstruction(PlanSession $session): string
    {
        $line = 'Something cheaper means every new idea is a different plan with different places, and its price is lower than the current plan.';
        $cap = self::referenceCost($session);

        if ($cap === null) {
            return $line.' Do not leave the price blank or at zero.';
        }

        $amount = '$'.self::money($cap);

        return $line.' Price cap: every new idea must cost less than '.$amount.'. Ignore any higher budget above. Use less expensive places. Do not repeat the current places and only lower the number.';
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return list<array<string, mixed>>
     */
    public static function cheaperThanCurrent(PlanSession $session, array $payloads): array
    {
        $previous = [];

        foreach ($session->suggestions as $suggestion) {
            $payload = is_array($suggestion->payload) ? $suggestion->payload : [];
            $name = self::nameKey((string) ($payload['name'] ?? $payload['title'] ?? ''));
            $previous[] = [
                'name' => $name,
                'cost' => self::askingPrice($payload),
                'places' => self::placeNames($payload),
            ];
        }

        $reference = self::referenceCost($session);
        $kept = [];

        foreach ($payloads as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $name = self::nameKey((string) ($payload['name'] ?? $payload['title'] ?? ''));
            $cost = self::askingPrice($payload);
            $places = self::placeNames($payload);
            $matched = null;

            foreach ($previous as $item) {
                $sameName = $name !== '' && $name === $item['name'];

                if (! $sameName && ! self::sharesPlace($places, $item['places'])) {
                    continue;
                }

                $matched = $item;
                break;
            }

            if ($matched !== null) {
                continue;
            }

            if ($cost === null || $cost <= 0 || ($reference !== null && $cost >= $reference)) {
                continue;
            }

            $kept[] = $payload;
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  list<string>  $places
     */
    public static function usesPlaces(array $content, array $places): bool
    {
        if ($places === []) {
            return true;
        }

        $stops = self::stopNames($content);

        foreach ($places as $place) {
            foreach ($stops as $stop) {
                if (self::overlaps($place, $stop)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  list<string>  $places
     */
    public static function usesAllPlaces(array $content, array $places): bool
    {
        if ($places === []) {
            return true;
        }

        $stops = self::stopNames($content);

        foreach ($places as $place) {
            $matched = false;

            foreach ($stops as $stop) {
                if (self::overlaps($place, $stop)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    public static function namesOverlap(string $left, string $right): bool
    {
        return self::overlaps($left, $right);
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  list<array<string, mixed>>  $requiredStops
     * @return array<string, mixed>
     */
    public static function mergeRequiredStops(array $content, array $requiredStops): array
    {
        if ($requiredStops === []) {
            return $content;
        }

        if (isset($content['stops']) && is_array($content['stops'])) {
            $content['stops'] = self::mergeStopList($content['stops'], $requiredStops);

            return $content;
        }

        if (isset($content['days']) && is_array($content['days']) && $content['days'] !== []) {
            $missing = $requiredStops;

            foreach ($content['days'] as $dayIndex => $day) {
                if (! is_array($day) || ! isset($day['stops']) || ! is_array($day['stops'])) {
                    continue;
                }

                $content['days'][$dayIndex]['stops'] = self::mergeStopList($day['stops'], $requiredStops);
                $missing = array_values(array_filter(
                    $missing,
                    fn (array $need): bool => ! self::listHasName($content['days'][$dayIndex]['stops'], (string) ($need['name'] ?? '')),
                ));
            }

            if ($missing !== [] && is_array($content['days'][0] ?? null)) {
                $firstStops = is_array($content['days'][0]['stops'] ?? null) ? $content['days'][0]['stops'] : [];
                $content['days'][0]['stops'] = array_merge($missing, $firstStops);
            }

            return $content;
        }

        $content['stops'] = $requiredStops;

        return $content;
    }

    /**
     * Keep the written night, and put the picked places on it when the draft used other names.
     *
     * @param  array<string, mixed>  $content
     * @param  list<string>  $places
     * @return array<string, mixed>
     */
    public static function anchorPlaces(array $content, array $places): array
    {
        $places = array_values(array_filter($places, fn ($place): bool => is_string($place) && trim($place) !== ''));

        if ($places === [] || self::usesPlaces($content, $places)) {
            return $content;
        }

        $index = 0;
        $rename = function (array $stops) use (&$index, $places): array {
            foreach ($stops as $stopIndex => $stop) {
                if (! is_array($stop) || $index >= count($places)) {
                    continue;
                }

                $stop['name'] = $places[$index];
                $stops[$stopIndex] = $stop;
                $index++;
            }

            return $stops;
        };

        if (isset($content['stops']) && is_array($content['stops'])) {
            $content['stops'] = $rename($content['stops']);
        }

        if (isset($content['days']) && is_array($content['days'])) {
            foreach ($content['days'] as $dayIndex => $day) {
                if (! is_array($day) || ! isset($day['stops']) || ! is_array($day['stops'])) {
                    continue;
                }

                $day['stops'] = $rename($day['stops']);
                $content['days'][$dayIndex] = $day;
            }
        }

        return $content;
    }

    public static function referenceCost(PlanSession $session): ?float
    {
        $lowest = null;

        foreach ($session->suggestions as $suggestion) {
            $payload = is_array($suggestion->payload) ? $suggestion->payload : [];
            $cost = self::askingPrice($payload);

            if ($cost === null || $cost <= 0) {
                continue;
            }

            if ($lowest === null || $cost < $lowest) {
                $lowest = $cost;
            }
        }

        return $lowest;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function priceLine(array $payload): string
    {
        $name = trim((string) ($payload['name'] ?? $payload['title'] ?? 'the selected option'));
        $cost = self::cost($payload);
        $price = $cost === null ? '' : ' The price already shown is $'.self::money($cost).'. Do not describe a more expensive plan.';

        return 'Write the plan for "'.$name.'" only. Use its places. Do not substitute a different plan.'.$price;
    }

    private static function latestNote(PlanSession $session): string
    {
        $note = '';

        foreach ($session->refinement_messages ?? [] as $message) {
            if (($message['role'] ?? '') === 'user' && is_string($message['content'] ?? null) && $message['content'] !== '') {
                $note = $message['content'];
            }
        }

        return $note;
    }

    private static function nameKey(string $name): string
    {
        $key = strtolower(trim($name));
        $key = preg_replace('/[^a-z0-9]+/', ' ', $key) ?? $key;

        return trim($key);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function advertisedCost(array $payload): ?float
    {
        $text = trim((string) ($payload['name'] ?? '').' '.(string) ($payload['description'] ?? ''));

        if ($text === '' || ! preg_match_all('/\$\s*(\d+(?:\.\d{1,2})?)/', $text, $matches)) {
            return null;
        }

        $highest = null;

        foreach ($matches[1] as $amount) {
            $value = (float) $amount;
            $highest = $highest === null ? $value : max($highest, $value);
        }

        return $highest;
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private static function sharesPlace(array $left, array $right): bool
    {
        foreach ($left as $place) {
            foreach ($right as $other) {
                if (self::overlaps($place, $other)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<string>
     */
    private static function stopNames(array $content): array
    {
        $stops = [];

        foreach ($content['stops'] ?? [] as $stop) {
            if (is_array($stop) && is_string($stop['name'] ?? null)) {
                $stops[] = $stop['name'];
            }
        }

        foreach ($content['days'] ?? [] as $day) {
            if (! is_array($day)) {
                continue;
            }

            foreach ($day['stops'] ?? [] as $stop) {
                if (is_array($stop) && is_string($stop['name'] ?? null)) {
                    $stops[] = $stop['name'];
                }
            }
        }

        return $stops;
    }

    /**
     * @param  list<mixed>  $stops
     * @param  list<array<string, mixed>>  $required
     * @return list<mixed>
     */
    private static function mergeStopList(array $stops, array $required): array
    {
        foreach ($required as $need) {
            $name = trim((string) ($need['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $found = false;

            foreach ($stops as $index => $stop) {
                if (! is_array($stop)) {
                    continue;
                }

                if (! self::stopMatchesNeed($stop, $need)) {
                    continue;
                }

                $stop['name'] = $name;
                $stop['event_id'] = $need['event_id'] ?? $stop['event_id'] ?? null;
                $stop['activity'] = self::richerText((string) ($stop['activity'] ?? ''), (string) ($need['activity'] ?? ''));
                $stop['notes'] = self::richerText((string) ($stop['notes'] ?? ''), (string) ($need['notes'] ?? ''));

                if (empty($stop['venue_url']) && ! empty($need['venue_url'])) {
                    $stop['venue_url'] = $need['venue_url'];
                }

                if (trim((string) ($stop['time'] ?? '')) === '' && ! empty($need['time'])) {
                    $stop['time'] = $need['time'];
                }

                $stops[$index] = $stop;
                $found = true;
                break;
            }

            if (! $found) {
                array_unshift($stops, $need);
            }
        }

        return array_values($stops);
    }

    /**
     * @param  list<mixed>  $stops
     */
    private static function listHasName(array $stops, string $name): bool
    {
        foreach ($stops as $stop) {
            if (is_array($stop) && self::stopMatchesNeed($stop, ['name' => $name])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $stop
     * @param  array<string, mixed>  $need
     */
    private static function stopMatchesNeed(array $stop, array $need): bool
    {
        $needName = trim((string) ($need['name'] ?? ''));
        $stopName = trim((string) ($stop['name'] ?? ''));

        if ($needName !== '' && $stopName !== '' && self::overlaps($needName, $stopName)) {
            return true;
        }

        $venue = trim((string) ($need['notes'] ?? $need['venue'] ?? ''));

        return $venue !== '' && $stopName !== '' && self::overlaps($venue, $stopName);
    }

    private static function richerText(string $written, string $fallback): string
    {
        $written = trim($written);
        $fallback = trim($fallback);

        if ($written === '') {
            return $fallback;
        }

        if ($fallback === '') {
            return $written;
        }

        return mb_strlen($written) >= mb_strlen($fallback) ? $written : $fallback;
    }

    private static function overlaps(string $place, string $stop): bool
    {
        $place = self::nameKey($place);
        $stop = self::nameKey($stop);

        if ($place === '' || $stop === '') {
            return false;
        }

        if ($place === $stop) {
            return true;
        }

        $compactPlace = str_replace(' ', '', $place);
        $compactStop = str_replace(' ', '', $stop);

        if (strlen($compactPlace) >= 5 && str_contains($compactStop, $compactPlace)) {
            return true;
        }

        if (strlen($compactStop) >= 5 && str_contains($compactPlace, $compactStop)) {
            return true;
        }

        $placeTokens = self::significantTokens($place);
        $stopTokens = self::significantTokens($stop);

        if ($placeTokens === [] || $stopTokens === []) {
            $shorter = strlen($place) <= strlen($stop) ? $place : $stop;
            $longer = $shorter === $place ? $stop : $place;

            return strlen($shorter) >= 4 && str_contains($longer, $shorter);
        }

        $shared = array_values(array_intersect($placeTokens, $stopTokens));

        if ($shared === []) {
            return false;
        }

        foreach ($shared as $token) {
            if (strlen($token) >= 5) {
                return true;
            }
        }

        return count($shared) >= 2;
    }

    /**
     * @return list<string>
     */
    private static function significantTokens(string $normalized): array
    {
        $noise = [
            'the', 'a', 'an', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'for', 'with', 'from',
            'bar', 'club', 'lounge', 'restaurant', 'cafe', 'theatre', 'theater', 'venue',
            'live', 'night', 'show', 'presents', 'featuring', 'event', 'tickets',
            'park', 'atlanta', 'georgia', 'usa', 'taste', 'historic', 'ward',
        ];
        $tokens = [];

        foreach (preg_split('/\s+/', $normalized) ?: [] as $token) {
            if (strlen($token) < 3 || in_array($token, $noise, true)) {
                continue;
            }

            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    private static function money(float $cost): string
    {
        $rounded = round($cost, 2);

        if (abs($rounded - round($rounded)) < 0.001) {
            return (string) (int) round($rounded);
        }

        return number_format($rounded, 2, '.', '');
    }
}
