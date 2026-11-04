<?php

namespace App\Services\AI;

use App\Models\PlanSession;
use App\Models\Suggestion;
use App\Services\AI\Prompts\PlanPromptBuilder;
use App\Services\AI\Prompts\PlanPromptBuilderResolver;
use App\Services\Events\PlanEventInventory;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SuggestionService
{
    public function __construct(
        private readonly AiChatClient $client,
        private readonly PlanPromptBuilderResolver $promptBuilderResolver,
        private readonly PlanEventInventory $inventory,
    ) {}

    /**
     * @return Collection<int, Suggestion>
     */
    public function generate(PlanSession $session): Collection
    {
        $catalog = $this->inventory->forSession($session);
        $builder = $this->promptBuilderResolver->resolve($session);
        $builder->setEventCatalog($catalog);

        $response = $this->client->chat([
            [
                'role' => 'system',
                'content' => $builder->suggestionSystemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $builder->suggestionUserPrompt($session),
            ],
        ]);

        $payloads = $this->groundedPayloads($builder, $response, $catalog);
        $rawCount = is_array($response['suggestions'] ?? null) ? count($response['suggestions']) : 0;

        if ($payloads === [] || (count($payloads) < 3 && $rawCount >= 3)) {
            $retry = $this->client->chat([
                [
                    'role' => 'system',
                    'content' => $builder->suggestionSystemPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => $builder->suggestionUserPrompt($session)
                        ."\nThe last ideas used invalid or missing event_ids. Return 3 to 5 different plans that only use catalog event_ids. Give each card different primary event_ids.",
                ],
            ]);
            $payloads = $this->groundedPayloads($builder, $retry, $catalog);
        }

        $payloads = $this->withoutStaleCheaperOffers($session, $builder, $catalog, $payloads);

        if ($payloads === []) {
            throw new InvalidArgumentException('No valid suggestions were generated from the local event catalog.');
        }

        $keepsSavedPlan = $session->itinerary()->exists();
        $session->suggestions()->delete();

        $suggestions = collect();

        foreach ($payloads as $payload) {
            $suggestions->push($session->suggestions()->create([
                'payload' => $payload,
            ]));
        }

        if (! $keepsSavedPlan) {
            $session->markStatus(PlanSession::STATUS_SUGGESTIONS);
        }

        return $suggestions;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<array{event_id: int, title: string, venue: ?string, starts_at: ?string, url: ?string, source: string}>  $catalog
     * @return list<array<string, mixed>>
     */
    private function groundedPayloads(PlanPromptBuilder $builder, array $response, array $catalog): array
    {
        if (! isset($response['suggestions']) || ! is_array($response['suggestions'])) {
            throw new InvalidArgumentException('AI response missing suggestions array.');
        }

        $payloads = [];
        $usedPrimary = [];

        foreach ($response['suggestions'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $payload = $this->bindCatalog($builder->normalizeSuggestionPayload($item), $catalog, $usedPrimary);

            if ($payload === null) {
                continue;
            }

            $primary = (int) ($payload['event_ids'][0] ?? 0);
            if ($primary > 0) {
                $usedPrimary[$primary] = true;
            }

            $payloads[] = $payload;
        }

        return $payloads;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{event_id: int, title: string, venue: ?string, starts_at: ?string, url: ?string, source: string}>  $catalog
     * @param  array<int, true>  $usedPrimary
     * @return array<string, mixed>|null
     */
    private function bindCatalog(array $payload, array $catalog, array $usedPrimary): ?array
    {
        $byId = [];

        foreach ($catalog as $row) {
            $byId[$row['event_id']] = $row;
        }

        $ids = [];

        foreach ($payload['event_ids'] ?? [] as $id) {
            $id = (int) $id;
            if (isset($byId[$id])) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            $ids = $this->idsMatchingNames($payload, $catalog);
        }

        if ($ids === []) {
            foreach ($catalog as $row) {
                $id = $row['event_id'];
                if (! isset($usedPrimary[$id])) {
                    $ids[] = $id;
                    break;
                }
            }
        }

        if ($ids === []) {
            return null;
        }

        $ids = array_values(array_unique($ids));
        $payload['event_ids'] = $ids;
        $titles = [];

        foreach ($ids as $id) {
            $titles[] = $byId[$id]['title'];
        }

        if (isset($payload['venues']) && is_array($payload['venues'])) {
            $payload['venues'] = array_values(array_unique([...$titles, ...$payload['venues']]));
        } elseif (isset($payload['highlights']) && is_array($payload['highlights'])) {
            $payload['highlights'] = array_values(array_unique([...$titles, ...$payload['highlights']]));
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{event_id: int, title: string, venue: ?string, starts_at: ?string, url: ?string, source: string}>  $catalog
     * @return list<int>
     */
    private function idsMatchingNames(array $payload, array $catalog): array
    {
        $names = PlanConsistency::placeNames($payload);
        $ids = [];

        foreach ($catalog as $row) {
            $haystack = trim($row['title'].' '.(string) $row['venue']);
            foreach ($names as $name) {
                if (PlanConsistency::namesOverlap($name, $haystack) || PlanConsistency::namesOverlap($name, $row['title'])) {
                    $ids[] = $row['event_id'];
                    break;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<array{event_id: int, title: string, venue: ?string, starts_at: ?string, url: ?string, source: string}>  $catalog
     * @param  list<array<string, mixed>>  $payloads
     * @return list<array<string, mixed>>
     */
    private function withoutStaleCheaperOffers(
        PlanSession $session,
        PlanPromptBuilder $builder,
        array $catalog,
        array $payloads,
    ): array {
        if (! PlanConsistency::wantsCheaper($session)) {
            return $payloads;
        }

        $kept = PlanConsistency::cheaperThanCurrent($session, $payloads);

        if ($kept !== []) {
            return $kept;
        }

        $reference = PlanConsistency::referenceCost($session);
        $limit = $reference === null ? 'the current ideas' : '$'.$reference;
        $retry = $this->client->chat([
            [
                'role' => 'system',
                'content' => $builder->suggestionSystemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $builder->suggestionUserPrompt($session)
                    ."\nThe last ideas repeated a current plan or were not cheaper. Return different plans using other catalog event_ids, and set every price lower than {$limit}.",
            ],
        ]);

        $kept = PlanConsistency::cheaperThanCurrent($session, $this->groundedPayloads($builder, $retry, $catalog));

        if ($kept === []) {
            throw new InvalidArgumentException('Cheaper options repeated the current plan or cost the same or more.');
        }

        return $kept;
    }
}
