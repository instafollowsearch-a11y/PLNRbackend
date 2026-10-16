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

            if ($name === '') {
                continue;
            }

            $previous[$name] = self::cost($payload);
        }

        $reference = self::referenceCost($session);
        $kept = [];

        foreach ($payloads as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $name = self::nameKey((string) ($payload['name'] ?? $payload['title'] ?? ''));
            $cost = self::cost($payload);

            if ($name !== '' && array_key_exists($name, $previous)) {
                $previousCost = $previous[$name];

                if ($previousCost === null || $cost === null || $cost >= $previousCost) {
                    continue;
                }
            }

            if ($reference !== null && ($cost === null || $cost >= $reference)) {
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

    public static function referenceCost(PlanSession $session): ?float
    {
        $selected = null;
        $lowest = null;

        foreach ($session->suggestions as $suggestion) {
            $payload = is_array($suggestion->payload) ? $suggestion->payload : [];
            $cost = self::cost($payload);

            if ($cost === null) {
                continue;
            }

            if ($suggestion->selected_at !== null) {
                $selected = $cost;
            }

            if ($lowest === null || $cost < $lowest) {
                $lowest = $cost;
            }
        }

        return $selected ?? $lowest;
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
