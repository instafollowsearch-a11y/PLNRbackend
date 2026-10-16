<?php

namespace App\Services\AI;

use App\Models\PlanSession;
use App\Models\Suggestion;
use App\Services\AI\Prompts\PlanPromptBuilder;
use App\Services\AI\Prompts\PlanPromptBuilderResolver;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SuggestionService
{
    public function __construct(
        private readonly AiChatClient $client,
        private readonly PlanPromptBuilderResolver $promptBuilderResolver,
    ) {}

    /**
     * @return Collection<int, Suggestion>
     */
    public function generate(PlanSession $session): Collection
    {
        $builder = $this->promptBuilderResolver->resolve($session);

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

        $payloads = $this->payloadsFrom($builder, $response);
        $payloads = $this->withoutStaleCheaperOffers($session, $builder, $payloads);

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
     * @return list<array<string, mixed>>
     */
    private function payloadsFrom(PlanPromptBuilder $builder, array $response): array
    {
        if (! isset($response['suggestions']) || ! is_array($response['suggestions'])) {
            throw new InvalidArgumentException('AI response missing suggestions array.');
        }

        $payloads = [];

        foreach ($response['suggestions'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $payloads[] = $builder->normalizeSuggestionPayload($item);
        }

        if ($payloads === []) {
            throw new InvalidArgumentException('No valid suggestions were generated.');
        }

        return $payloads;
    }

    /**
     * A cheaper request must not come back as the same plan, or as a higher price.
     *
     * @param  list<array<string, mixed>>  $payloads
     * @return list<array<string, mixed>>
     */
    private function withoutStaleCheaperOffers(PlanSession $session, PlanPromptBuilder $builder, array $payloads): array
    {
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
                    ."\nThe last ideas repeated a current plan or were not cheaper. Return different plans, and set every price lower than {$limit}.",
            ],
        ]);

        $kept = PlanConsistency::cheaperThanCurrent($session, $this->payloadsFrom($builder, $retry));

        if ($kept === []) {
            throw new InvalidArgumentException('Cheaper options repeated the current plan or cost the same or more.');
        }

        return $kept;
    }
}
