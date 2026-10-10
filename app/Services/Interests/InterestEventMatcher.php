<?php

namespace App\Services\Interests;

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Collection;
use Wamania\Snowball\Stemmer\English;

class InterestEventMatcher
{
    public const MAX_MATCHES = 5;

    public const WINDOW_DAYS = 7;

    /**
     * Words that show up in both interests and listings without saying what the event is.
     *
     * @var array<string, true>
     */
    private const STOP_WORDS = [
        'a' => true,
        'an' => true,
        'the' => true,
        'and' => true,
        'or' => true,
        'of' => true,
        'to' => true,
        'for' => true,
        'in' => true,
        'on' => true,
        'at' => true,
        'with' => true,
        'from' => true,
        'by' => true,
        'my' => true,
        'your' => true,
        'our' => true,
        'live' => true,
        'show' => true,
        'shows' => true,
        'night' => true,
        'nights' => true,
        'event' => true,
        'events' => true,
        'this' => true,
        'that' => true,
        'into' => true,
        'over' => true,
    ];

    private readonly English $stemmer;

    /** @var array<string, list<string>> */
    private array $tokenCache = [];

    /** @var array<string, Collection<int, Event>> */
    private array $eventsByCity = [];

    public function __construct()
    {
        $this->stemmer = new English;
    }

    /**
     * @return list<array{event_id: int, matched_interest: string, score: int}>
     */
    public function match(User $user): array
    {
        $interests = $this->interests($user);

        if ($interests === [] || trim((string) $user->city) === '') {
            return [];
        }

        $ranked = [];

        foreach ($this->eventsFor((string) $user->city) as $event) {
            $eventTokens = $this->tokens(trim($event->title.' '.($event->description ?? '')));
            $bestInterest = null;
            $bestScore = 0;
            $bestSpecificity = 0;

            foreach ($interests as $interest) {
                $interestTokens = $this->tokens($interest);

                if ($interestTokens === []) {
                    continue;
                }

                $score = count(array_intersect($interestTokens, $eventTokens));

                if ($score < 1) {
                    continue;
                }

                $specificity = count($interestTokens);

                if ($score > $bestScore || ($score === $bestScore && $specificity > $bestSpecificity)) {
                    $bestScore = $score;
                    $bestSpecificity = $specificity;
                    $bestInterest = $interest;
                }
            }

            if ($bestInterest === null) {
                continue;
            }

            $ranked[] = [
                'event_id' => $event->id,
                'matched_interest' => $bestInterest,
                'score' => $bestScore,
                'starts_at' => $event->starts_at?->getTimestamp() ?? PHP_INT_MAX,
            ];
        }

        usort($ranked, function (array $left, array $right): int {
            if ($left['score'] !== $right['score']) {
                return $right['score'] <=> $left['score'];
            }

            return $left['starts_at'] <=> $right['starts_at'];
        });

        return array_map(
            static fn (array $row): array => [
                'event_id' => $row['event_id'],
                'matched_interest' => $row['matched_interest'],
                'score' => $row['score'],
            ],
            array_slice($ranked, 0, self::MAX_MATCHES),
        );
    }

    /**
     * @return list<string>
     */
    private function interests(User $user): array
    {
        if (! is_array($user->interests)) {
            return [];
        }

        $interests = [];

        foreach ($user->interests as $interest) {
            $label = trim((string) $interest);

            if ($label !== '') {
                $interests[] = $label;
            }
        }

        return $interests;
    }

    /**
     * @return Collection<int, Event>
     */
    private function eventsFor(string $city): Collection
    {
        $key = mb_strtolower(trim($city));

        if (! array_key_exists($key, $this->eventsByCity)) {
            $this->eventsByCity[$key] = Event::query()
                ->whereRaw('LOWER(city) = ?', [$key])
                ->where('starts_at', '>=', now())
                ->where('starts_at', '<', now()->addDays(self::WINDOW_DAYS))
                ->orderBy('starts_at')
                ->get();
        }

        return $this->eventsByCity[$key];
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $cacheKey = mb_strtolower($text);

        if (array_key_exists($cacheKey, $this->tokenCache)) {
            return $this->tokenCache[$cacheKey];
        }

        $parts = preg_split('/[^a-z0-9]+/i', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $tokens = [];

        foreach ($parts ?: [] as $part) {
            if (! preg_match('/^[a-z]+$/', $part) || isset(self::STOP_WORDS[$part])) {
                continue;
            }

            if (strlen($part) < 3) {
                $tokens[$part] = $part;

                continue;
            }

            $stem = strtolower(trim((string) $this->stemmer->stem($part)));

            if ($stem !== '' && ! isset(self::STOP_WORDS[$stem])) {
                $tokens[$stem] = $stem;
            }
        }

        return $this->tokenCache[$cacheKey] = array_values($tokens);
    }
}
