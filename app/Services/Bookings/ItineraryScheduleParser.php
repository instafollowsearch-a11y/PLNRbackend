<?php

namespace App\Services\Bookings;

use App\Models\PlanSession;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class ItineraryScheduleParser
{
    /**
     * @param  array<string, mixed>  $content
     */
    public function firstStopTime(array $content, ?PlanSession $planSession = null): CarbonInterface
    {
        $stops = $this->allStops($content, $planSession);

        if ($stops === []) {
            return now()->addDay();
        }

        return $stops[0]['at'];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<array{
     *     stop_index: int,
     *     day_index: int|null,
     *     name: string,
     *     activity: string|null,
     *     notes: string|null,
     *     at: CarbonInterface
     * }>
     */
    public function allStops(array $content, ?PlanSession $planSession = null): array
    {
        $results = [];

        if (isset($content['stops']) && is_array($content['stops'])) {
            foreach (array_values($content['stops']) as $index => $stop) {
                if (! is_array($stop)) {
                    continue;
                }

                $parsed = $this->parseStopAt($stop, $planSession, null);

                if ($parsed === null) {
                    continue;
                }

                $results[] = [
                    'stop_index' => $index,
                    'day_index' => null,
                    'name' => $this->stringOrFallback($stop['name'] ?? null, 'Stop '.($index + 1)),
                    'activity' => $this->nullableString($stop['activity'] ?? null),
                    'notes' => $this->nullableString($stop['notes'] ?? null),
                    'at' => $parsed,
                ];
            }
        }

        if (isset($content['days']) && is_array($content['days'])) {
            foreach (array_values($content['days']) as $dayIndex => $day) {
                if (! is_array($day)) {
                    continue;
                }

                $dayDate = $this->resolveDayDate($day, $planSession, $dayIndex);

                foreach (array_values($day['stops'] ?? []) as $stopIndex => $stop) {
                    if (! is_array($stop)) {
                        continue;
                    }

                    $parsed = $this->parseStopAt($stop, $planSession, $dayDate);

                    if ($parsed === null) {
                        continue;
                    }

                    $results[] = [
                        'stop_index' => $stopIndex,
                        'day_index' => $dayIndex,
                        'name' => $this->stringOrFallback($stop['name'] ?? null, 'Stop '.($stopIndex + 1)),
                        'activity' => $this->nullableString($stop['activity'] ?? null),
                        'notes' => $this->nullableString($stop['notes'] ?? null),
                        'at' => $parsed,
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $stop
     */
    private function parseStopAt(array $stop, ?PlanSession $planSession, ?string $dayDate): ?CarbonInterface
    {
        $time = $stop['time'] ?? null;

        if (! is_string($time) || trim($time) === '') {
            return null;
        }

        $time = trim($time);

        try {
            if (preg_match('/^\d{1,2}:\d{2}$/', $time) === 1) {
                $baseDate = $dayDate ?? $this->resolveBaseDate($planSession);
                $parsed = Carbon::parse($baseDate.' '.$time);

                if ($dayDate === null && $parsed->isPast()) {
                    $parsed->addDay();
                }

                return $parsed;
            }

            return Carbon::parse($time);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function resolveDayDate(array $day, ?PlanSession $planSession, int $dayIndex): string
    {
        $raw = $day['date'] ?? null;

        if (is_string($raw) && $raw !== '') {
            try {
                return Carbon::parse($raw)->toDateString();
            } catch (\Throwable) {
                // Fall through for labels like "Day 1"
            }

            if (preg_match('/day\s*(\d+)/i', $raw, $matches) === 1) {
                $offset = max(0, ((int) $matches[1]) - 1);
                $start = $this->resolveVacationStartDate($planSession);

                return Carbon::parse($start)->addDays($offset)->toDateString();
            }
        }

        $start = $this->resolveVacationStartDate($planSession);

        return Carbon::parse($start)->addDays($dayIndex)->toDateString();
    }

    private function resolveVacationStartDate(?PlanSession $planSession): string
    {
        if ($planSession === null) {
            return now()->toDateString();
        }

        $answers = $planSession->answers ?? [];
        $dates = $answers['dates'] ?? null;

        if (is_string($dates) && $dates !== '') {
            if (str_contains($dates, ' to ')) {
                $parts = explode(' to ', $dates, 2);

                try {
                    return Carbon::parse(trim($parts[0]))->toDateString();
                } catch (\Throwable) {
                    //
                }
            }

            try {
                return Carbon::parse($dates)->toDateString();
            } catch (\Throwable) {
                //
            }
        }

        if (is_array($dates)) {
            $start = $dates['start'] ?? $dates[0] ?? null;

            if (is_string($start) && $start !== '') {
                try {
                    return Carbon::parse($start)->toDateString();
                } catch (\Throwable) {
                    //
                }
            }
        }

        return $this->resolveBaseDate($planSession);
    }

    private function resolveBaseDate(?PlanSession $planSession): string
    {
        if ($planSession !== null) {
            $answers = $planSession->answers ?? [];
            $dates = $answers['dates'] ?? null;

            if (is_string($dates) && $dates !== '') {
                if (str_contains($dates, ' to ')) {
                    $parts = explode(' to ', $dates, 2);

                    try {
                        return Carbon::parse(trim($parts[0]))->toDateString();
                    } catch (\Throwable) {
                        //
                    }
                }

                try {
                    return Carbon::parse($dates)->toDateString();
                } catch (\Throwable) {
                    //
                }
            }

            if (is_array($dates) && ! empty($dates)) {
                $first = $dates['start'] ?? $dates[0] ?? null;

                if (is_string($first) && $first !== '') {
                    try {
                        return Carbon::parse($first)->toDateString();
                    } catch (\Throwable) {
                        //
                    }
                }
            }

            $arrival = $answers['arrival_date'] ?? null;

            if (is_string($arrival) && $arrival !== '') {
                try {
                    return Carbon::parse($arrival)->toDateString();
                } catch (\Throwable) {
                    //
                }
            }
        }

        return now()->toDateString();
    }

    private function stringOrFallback(mixed $value, string $fallback): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return $fallback;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
