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

    private static function overlaps(string $place, string $stop): bool
    {
        $place = strtolower(trim($place));
        $stop = strtolower(trim($stop));

        if ($place === '' || $stop === '') {
            return false;
        }

        if ($place === $stop) {
            return true;
        }

        $shorter = strlen($place) <= strlen($stop) ? $place : $stop;
        $longer = $shorter === $place ? $stop : $place;

        return strlen($shorter) >= 4 && str_contains($longer, $shorter);
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
