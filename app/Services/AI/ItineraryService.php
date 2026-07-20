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

    public function generate(PlanSession $session, Suggestion $suggestion): Itinerary
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
        ]);

        $content = $builder->normalizeItineraryContent($response);

        $session->itinerary()?->delete();

        $itinerary = $session->itinerary()->create([
            'content' => $content,
        ]);

        $session->markStatus(PlanSession::STATUS_ITINERARY);

        return $itinerary;
    }
}
