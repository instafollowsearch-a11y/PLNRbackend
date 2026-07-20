<?php

namespace App\Services\AI;

use App\Models\PlanSession;
use App\Models\Suggestion;
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

        if (! isset($response['suggestions']) || ! is_array($response['suggestions'])) {
            throw new InvalidArgumentException('AI response missing suggestions array.');
        }

        $session->suggestions()->delete();

        $suggestions = collect();

        foreach ($response['suggestions'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $payload = $builder->normalizeSuggestionPayload($item);

            $suggestions->push($session->suggestions()->create([
                'payload' => $payload,
            ]));
        }

        if ($suggestions->isEmpty()) {
            throw new InvalidArgumentException('No valid suggestions were generated.');
        }

        $session->markStatus(PlanSession::STATUS_SUGGESTIONS);

        return $suggestions;
    }
}
