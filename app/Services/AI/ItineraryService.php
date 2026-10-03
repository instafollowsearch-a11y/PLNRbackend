<?php

namespace App\Services\AI;

use App\Models\Itinerary;
use App\Models\PlanSession;
use App\Models\Suggestion;
use App\Services\AI\Prompts\PlanPromptBuilderResolver;
use App\Services\AI\Prompts\VacationPromptBuilder;
use App\Services\Places\PlaceListingLookup;

class ItineraryService
{
    public function __construct(
        private readonly AiChatClient $client,
        private readonly PlanPromptBuilderResolver $promptBuilderResolver,
        private readonly PlaceListingLookup $placeListingLookup,
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

        $content = $builder->normalizeItineraryContent($response);

        if ($builder instanceof VacationPromptBuilder) {
            $content = $builder->applyTripDates($session, $content);
        }

        return $this->placeListingLookup->enrich(
            $content,
            is_string($session->city) ? $session->city : null,
        );
    }

    private function hasDraft(Suggestion $suggestion): bool
    {
        return is_array($suggestion->itinerary_content) && $suggestion->itinerary_content !== [];
    }
}
