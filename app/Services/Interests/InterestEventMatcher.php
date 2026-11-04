<?php

namespace App\Services\Interests;

use App\Models\Event;
use App\Models\PlanSession;
use App\Models\User;
use App\Services\AI\PlanConsistency;
use App\Services\AI\Prompts\AbstractPlanPromptBuilder;
use App\Services\Events\CityListingInventory;
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

    /**
     * Extra stems so "Live music" can hit jazz/concert listings.
     *
     * @var array<string, list<string>>
     */
    private const SYNONYMS = [
        'music' => ['jazz', 'concert', 'band', 'gig', 'orchestra', 'blues', 'rock', 'dj', 'singer', 'karaoke'],
        'jazz' => ['music', 'concert', 'sax', 'improv'],
        'comedi' => ['improv', 'standup', 'comic', 'laugh'],
        'comedy' => ['improv', 'standup', 'comic', 'laugh'],
        'art' => ['galleri', 'museum', 'exhibit'],
        'galleri' => ['art', 'museum', 'exhibit'],
        'theater' => ['theatr', 'play', 'musical', 'drama'],
        'theatr' => ['theater', 'play', 'musical', 'drama'],
        'sport' => ['game', 'match', 'stadium', 'team'],
        'club' => ['nightlif', 'dj', 'dance', 'nightlife'],
        'nightlif' => ['club', 'dj', 'dance'],
        'food' => ['festival', 'market', 'taco', 'restaurant', 'brunch'],
    ];

    private readonly English $stemmer;

    /** @var array<string, list<string>> */
    private array $tokenCache = [];

    /** @var array<string, Collection<int, Event>> */
    private array $eventsByCity = [];

    public function __construct(private readonly CityListingInventory $listings)
    {
        $this->stemmer = new English;
    }

    /**
     * @return list<array{event_id: int, matched_interest: string, score: int}>
     */
    public function match(User $user): array
    {
        $interests = $this->interests($user);
        $city = trim((string) $user->city);

        if ($interests === [] || $city === '') {
            return [];
        }

        $ranked = [];

        foreach ($this->eventsFor($city) as $event) {
            $haystack = trim($event->title.' '.($event->venue_name ?? '').' '.($event->description ?? ''));
            $eventTokens = $this->tokens($haystack);
            $bestInterest = null;
            $bestScore = 0;
            $bestSpecificity = 0;

            foreach ($interests as $interest) {
                $score = $this->scoreInterest($interest, $haystack, $eventTokens);

                if ($score < 1) {
                    continue;
                }

                $specificity = count($this->tokens($interest));

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
    public function labelsFor(User $user): array
    {
        return $this->interests($user);
    }

    /**
     * @return list<string>
     */
    private function interests(User $user): array
    {
        $interests = [];
        $seen = [];

        foreach ($this->labels($user->interests) as $label) {
            $this->pushInterest($interests, $seen, $label);
        }

        $cityKey = $this->listings->cityKey((string) $user->city);

        if ($cityKey === '' || $user->id === null) {
            return $interests;
        }

        $sessions = PlanSession::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(12)
            ->get(['id', 'city', 'answers']);

        foreach ($sessions as $session) {
            if ($this->listings->cityKey((string) $session->city) !== $cityKey) {
                continue;
            }

            $answers = is_array($session->answers) ? $session->answers : [];

            foreach ($this->labels($answers['interests'] ?? null) as $label) {
                $this->pushInterest($interests, $seen, $label);
            }
        }

        return $interests;
    }

    /**
     * @param  list<string>  $interests
     * @param  array<string, true>  $seen
     */
    private function pushInterest(array &$interests, array &$seen, string $label): void
    {
        if ($label === '' || str_contains($label, AbstractPlanPromptBuilder::OPEN_TO_SUGGESTIONS)) {
            return;
        }

        $key = mb_strtolower($label);

        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $interests[] = $label;
    }

    /**
     * @return list<string>
     */
    private function labels(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,|]/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $labels = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $item['label'] ?? $item['name'] ?? '';
            }

            $label = trim((string) $item);

            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * @return Collection<int, Event>
     */
    private function eventsFor(string $city): Collection
    {
        $cityKey = $this->listings->cityKey($city);
        $cacheKey = mb_strtolower($cityKey);

        if (! array_key_exists($cacheKey, $this->eventsByCity)) {
            $this->eventsByCity[$cacheKey] = $this->listings->queryEvents(
                $city,
                $cityKey,
                now(),
                now()->addDays(self::WINDOW_DAYS),
            );
        }

        return $this->eventsByCity[$cacheKey];
    }

    /**
     * @param  list<string>  $eventTokens
     */
    private function scoreInterest(string $interest, string $haystack, array $eventTokens): int
    {
        $score = 0;

        if (PlanConsistency::namesOverlap($interest, $haystack)) {
            $score += 2;
        }

        $interestTokens = $this->expandedTokens($interest);

        if ($interestTokens === [] || $eventTokens === []) {
            return $score;
        }

        $shared = count(array_intersect($interestTokens, $eventTokens));

        return $score + $shared;
    }

    /**
     * @return list<string>
     */
    private function expandedTokens(string $interest): array
    {
        $tokens = $this->tokens($interest);
        $expanded = $tokens;

        foreach ($tokens as $token) {
            foreach (self::SYNONYMS[$token] ?? [] as $synonym) {
                $expanded[] = $synonym;
            }
        }

        return array_values(array_unique($expanded));
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
