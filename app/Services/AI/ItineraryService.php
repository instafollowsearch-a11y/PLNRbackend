<?php

namespace App\Services\AI;

use App\Models\Itinerary;
use App\Models\PlanSession;
use App\Models\Suggestion;
use App\Services\AI\Prompts\PlanPromptBuilderResolver;

class ItineraryService
{
    public function __construct(
        private readonly AiChatClient $client,
        private readonly PlanPromptBuilderResolver $promptBuilderResolver,
    ) {}

    public function draft(PlanSession $session, Suggestion $suggestion): Suggestion
    {
        if ($this->hasDraft($suggestion)) {
            return $suggestion;
        }

        $suggestion->update([
            'itinerary_content' => $this->writeContent($session, $suggestion),
        ]);

        return $suggestion->fresh() ?? $suggestion;
    }

    public function generate(PlanSession $session, Suggestion $suggestion): Itinerary
    {
        $content = $this->hasDraft($suggestion)
            ? $suggestion->itinerary_content
            : $this->writeContent($session, $suggestion);

        $session->itinerary()?->delete();

        $itinerary = $session->itinerary()->create([
            'content' => $content,
        ]);

        $session->markStatus(PlanSession::STATUS_ITINERARY);

        return $itinerary;
    }

    /**
     * @return array<string, mixed>
     */
    private function writeContent(PlanSession $session, Suggestion $suggestion): array
    {
        $builder = $this->promptBuilderResolver->resolve($session);

        $response = $this->client->chat([
            [
                'role' => 'system',
                'content' => $builder->itinerarySystemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $builder->itineraryUserPrompt($session, $suggestion),
            ],
        ], true, 8192);

        return $builder->normalizeItineraryContent($response);
    }

    private function hasDraft(Suggestion $suggestion): bool
    {
        return is_array($suggestion->itinerary_content) && $suggestion->itinerary_content !== [];
    }
}
