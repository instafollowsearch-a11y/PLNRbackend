<?php

namespace App\Services\AI;

class PlanPacing
{
    /**
     * A local night should not leave a two-hour hole between one outing and the next.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function tighten(array $content, string $slug): array
    {
        if (! in_array($slug, ['night_out', 'date_night'], true)) {
            return $content;
        }

        if (isset($content['stops']) && is_array($content['stops'])) {
            $content['stops'] = self::tightenList($content['stops']);
        }

        if (isset($content['days']) && is_array($content['days'])) {
            foreach ($content['days'] as $index => $day) {
                if (! is_array($day) || ! isset($day['stops']) || ! is_array($day['stops'])) {
                    continue;
                }

                $day['stops'] = self::tightenList($day['stops']);
                $content['days'][$index] = $day;
            }
        }

        return $content;
    }

    /**
     * @param  list<mixed>  $stops
     * @return list<mixed>
     */
    private static function tightenList(array $stops): array
    {
        $previousStart = null;

        foreach ($stops as $index => $stop) {
            if (! is_array($stop) || ! is_string($stop['time'] ?? null) || trim($stop['time']) === '') {
                continue;
            }

            $window = self::windowMinutes($stop['time']);

            if ($window === null) {
                continue;
            }

            $start = $window['start'];

            if ($previousStart !== null && $start < $previousStart) {
                $start += 24 * 60;
                $window['end'] += 24 * 60;
            }

            if ($previousStart !== null && $start - $previousStart > 100) {
                $shift = ($previousStart + 75) - $start;
                $start += $shift;
                $window['end'] += $shift;
                $stop['time'] = self::formatWindow($start, $window['end'], $window['style'], $window['sharedPeriod']);
                $stops[$index] = $stop;
            }

            $previousStart = $start;
        }

        return $stops;
    }

    /**
     * @return array{start: int, end: int, style: string, sharedPeriod: bool}|null
     */
    private static function windowMinutes(string $time): ?array
    {
        if (! preg_match_all('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $time, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $clocks = [];
        $period = null;

        foreach (array_reverse($matches) as $match) {
            if (($match[3] ?? '') !== '') {
                $period = strtolower($match[3]);
            }

            $clocks[] = ['hour' => (int) $match[1], 'minute' => (int) ($match[2] ?? 0), 'period' => $period];
        }

        $clocks = array_reverse($clocks);
        $start = self::toMinutes($clocks[0]);

        if ($start === null) {
            return null;
        }

        $end = $start;
        $shared = count($clocks) > 1 && ($clocks[0]['period'] ?? null) !== null && ($clocks[0]['period'] ?? null) === ($clocks[count($clocks) - 1]['period'] ?? null);

        if (isset($clocks[1])) {
            $end = self::toMinutes($clocks[1]);

            if ($end === null) {
                $end = $start;
            } elseif ($end <= $start) {
                $end += 24 * 60;
            }
        }

        $style = ($clocks[0]['period'] ?? null) === null && $clocks[0]['hour'] > 12 ? '24' : '12';

        return [
            'start' => $start,
            'end' => $end,
            'style' => $style,
            'sharedPeriod' => $shared && $end - $start < 24 * 60,
        ];
    }

    /**
     * @param  array{hour: int, minute: int, period: ?string}  $clock
     */
    private static function toMinutes(array $clock): ?int
    {
        $hour = $clock['hour'];
        $minute = $clock['minute'];

        if ($minute > 59 || $hour > 23) {
            return null;
        }

        $period = $clock['period'];

        if ($period === 'am' || $period === 'pm') {
            if ($hour > 12) {
                return null;
            }

            $hour = $hour % 12;

            if ($period === 'pm') {
                $hour += 12;
            }
        }

        return $hour * 60 + $minute;
    }

    private static function formatWindow(int $start, int $end, string $style, bool $sharedPeriod): string
    {
        $startLabel = self::formatClock($start, $style, $sharedPeriod);
        $endLabel = self::formatClock($end, $style, false);

        if ($start === $end) {
            return $endLabel;
        }

        return $startLabel.' – '.$endLabel;
    }

    private static function formatClock(int $minutes, string $style, bool $hidePeriod): string
    {
        $minutes = $minutes % (24 * 60);
        $hour = intdiv($minutes, 60);
        $minute = $minutes % 60;

        if ($style === '24') {
            return sprintf('%d:%02d', $hour, $minute);
        }

        $period = $hour >= 12 ? 'PM' : 'AM';
        $hour12 = $hour % 12;

        if ($hour12 === 0) {
            $hour12 = 12;
        }

        $label = sprintf('%d:%02d', $hour12, $minute);

        return $hidePeriod ? $label : $label.' '.$period;
    }
}
