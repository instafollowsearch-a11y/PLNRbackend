<?php

namespace App\Services\AI;

use App\Models\Itinerary;
use App\Models\PlanSession;
use App\Models\Suggestion;
use App\Services\AI\Prompts\PlanPromptBuilderResolver;
use App\Services\AI\Prompts\RoadTripPromptBuilder;
use App\Services\AI\Prompts\VacationPromptBuilder;
use App\Services\Places\AreaLimit;
use App\Services\Places\PlaceListingLookup;
use App\Services\Places\RoadTripDriveEstimate;
use InvalidArgumentException;

class ItineraryService
{
    public function __construct(
        private readonly AiChatClient $client,
        private readonly PlanPromptBuilderResolver $promptBuilderResolver,
        private readonly PlaceListingLookup $placeListingLookup,
        private readonly RoadTripDriveEstimate $roadTripDriveEstimate,
    ) {}

    public function draft(PlanSession $session, Suggestion $suggestion): Suggestion
    {
        if ($this->hasDraft($suggestion)) {
            return $suggestion;
        }

        $content = $this->writeContent($session, $suggestion);
        $this->rememberRoadTripEstimate($suggestion, $content);

        $suggestion->update([
            'itinerary_content' => $content,
            'payload' => $suggestion->payload,
        ]);

        return $suggestion->fresh() ?? $suggestion;
    }

    public function generate(PlanSession $session, Suggestion $suggestion): Itinerary
    {
        if ($this->hasDraft($suggestion)) {
            $content = $suggestion->itinerary_content;
        } else {
            $content = $this->writeContent($session, $suggestion);
            $this->rememberRoadTripEstimate($suggestion, $content);
            $suggestion->update(['payload' => $suggestion->payload]);
        }

        $session->itinerary()?->delete();

        $itinerary = $session->itinerary()->create([
            'suggestion_id' => $suggestion->id,
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
        $places = PlanConsistency::placeNames($suggestion->payload ?? []);
        $extra = '';

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $content = $this->requestContent($session, $suggestion, $extra);

            if (PlanConsistency::usesPlaces($content, $places)) {
                return $content;
            }

            $extra = "\nThe previous draft did not use the selected places. Rewrite it for these places only: ".implode(', ', $places);
        }

        throw new InvalidArgumentException('The written plan did not match the selected option.');
    }

    /**
     * @return array<string, mixed>
     */
    private function requestContent(PlanSession $session, Suggestion $suggestion, string $extra): array
    {
        $builder = $this->promptBuilderResolver->resolve($session);

        $response = $this->client->chat([
            [
                'role' => 'system',
                'content' => $builder->itinerarySystemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $builder->itineraryUserPrompt($session, $suggestion).$extra,
            ],
        ], true, 8192);

        $content = $builder->normalizeItineraryContent($response);

        if ($builder instanceof VacationPromptBuilder || $builder instanceof RoadTripPromptBuilder) {
            $content = $builder->applyTripDates($session, $content);
        }

        $isRoadTrip = $builder->slug() === 'road_trip';
        $content = $this->placeListingLookup->enrich(
            $content,
            is_string($session->city) ? $session->city : null,
            AreaLimit::fromAnswers($session->answers ?? []),
            $isRoadTrip,
        );

        if (! $isRoadTrip) {
            return $content;
        }

        $carType = $session->answers['car_type'] ?? null;

        return $this->roadTripDriveEstimate->apply(
            $content,
            is_string($carType) ? $carType : null,
        );
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function rememberRoadTripEstimate(Suggestion $suggestion, array $content): void
    {
        if (! isset($content['total_drive_time']) || ! is_string($content['total_drive_time'])) {
            return;
        }

        $payload = $suggestion->payload ?? [];
        $payload['total_drive_time'] = $content['total_drive_time'];

        if (isset($content['estimated_gas_cost']) && is_numeric($content['estimated_gas_cost'])) {
            $payload['estimated_gas_cost'] = (float) $content['estimated_gas_cost'];
        }

        $suggestion->payload = $payload;
    }

    private function hasDraft(Suggestion $suggestion): bool
    {
        return is_array($suggestion->itinerary_content) && $suggestion->itinerary_content !== [];
    }
}
