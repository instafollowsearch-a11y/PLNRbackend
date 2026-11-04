<?php

namespace App\Services\AI;

use App\Models\Event;
use App\Models\Itinerary;
use App\Models\PlanSession;
use App\Models\Suggestion;
use App\Services\AI\Prompts\PlanPromptBuilderResolver;
use App\Services\AI\Prompts\RoadTripPromptBuilder;
use App\Services\AI\Prompts\VacationPromptBuilder;
use App\Services\Events\FindLocalStopLinks;
use App\Services\Events\PlanEventInventory;
use App\Services\Places\AreaLimit;
use App\Services\Places\LookupPin;
use App\Services\Places\PlaceListingLookup;
use App\Services\Places\RoadTripDriveEstimate;
use App\Support\Geo\CoordinateTimeZone;

class ItineraryService
{
    public function __construct(
        private readonly AiChatClient $client,
        private readonly PlanPromptBuilderResolver $promptBuilderResolver,
        private readonly PlaceListingLookup $placeListingLookup,
        private readonly RoadTripDriveEstimate $roadTripDriveEstimate,
        private readonly CoordinateTimeZone $timeZones,
        private readonly PlanEventInventory $inventory,
        private readonly FindLocalStopLinks $eventLinks,
    ) {}

    public function draft(PlanSession $session, Suggestion $suggestion): Suggestion
    {
        if ($this->hasDraft($suggestion)) {
            return $suggestion;
        }

        $pin = new LookupPin;
        $content = $this->writeContent($session, $suggestion, $pin);
        $this->finishPlan($session, $content, $pin);
        $this->rememberRoadTripEstimate($suggestion, $content);

        $suggestion->update([
            'itinerary_content' => $content,
            'payload' => $suggestion->payload,
        ]);

        return $suggestion->fresh() ?? $suggestion;
    }

    public function generate(PlanSession $session, Suggestion $suggestion): Itinerary
    {
        $pin = new LookupPin;

        if ($this->hasDraft($suggestion)) {
            $content = $suggestion->itinerary_content;
        } else {
            $content = $this->writeContent($session, $suggestion, $pin);
            $this->rememberRoadTripEstimate($suggestion, $content);
            $suggestion->update(['payload' => $suggestion->payload]);
        }

        $this->finishPlan($session, is_array($content) ? $content : [], $pin);

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
    private function writeContent(PlanSession $session, Suggestion $suggestion, LookupPin $pin): array
    {
        $required = $this->requiredEventStops($suggestion);
        $places = $required !== []
            ? array_values(array_filter(array_map(
                static fn (array $stop): string => (string) ($stop['name'] ?? ''),
                $required,
            )))
            : PlanConsistency::placeNames($suggestion->payload ?? []);
        $slug = $this->promptBuilderResolver->resolve($session)->slug();
        $content = $this->requestContent($session, $suggestion);

        if ($required !== []) {
            $content = PlanConsistency::mergeRequiredStops($content, $required);

            if (! PlanConsistency::usesAllPlaces($content, $places)) {
                $content = PlanConsistency::mergeRequiredStops($content, $required);
            }
        } elseif (! PlanConsistency::usesPlaces($content, $places)) {
            $content = PlanConsistency::anchorPlaces($content, $places);
        }

        return $this->withListings($session, PlanPacing::tighten($content, $slug), $pin);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestContent(PlanSession $session, Suggestion $suggestion): array
    {
        $builder = $this->promptBuilderResolver->resolve($session);
        $builder->setEventCatalog($this->inventory->catalogOnly($session));

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

        if ($builder instanceof VacationPromptBuilder || $builder instanceof RoadTripPromptBuilder) {
            $content = $builder->applyTripDates($session, $content);
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function withListings(PlanSession $session, array $content, LookupPin $pin): array
    {
        $builder = $this->promptBuilderResolver->resolve($session);
        $isRoadTrip = $builder->slug() === 'road_trip';
        $content = $this->placeListingLookup->enrich(
            $content,
            is_string($session->city) ? $session->city : null,
            AreaLimit::fromAnswers($session->answers ?? []),
            $isRoadTrip,
            $pin,
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
    private function finishPlan(PlanSession $session, array $content, LookupPin $pin): void
    {
        $this->rememberTimeZone($session, $pin);
        $this->placeListingLookup->warmPhotos($content);
    }

    private function rememberTimeZone(PlanSession $session, LookupPin $pin): void
    {
        $answers = is_array($session->answers) ? $session->answers : [];

        if (is_string($answers['timezone'] ?? null) && trim($answers['timezone']) !== '') {
            return;
        }

        $zone = null;

        if ($pin->latitude !== null && $pin->longitude !== null) {
            $zone = $this->timeZones->find($pin->latitude, $pin->longitude);
        } elseif (! app()->runningUnitTests()) {
            $zone = $this->timeZones->findForCity(is_string($session->city) ? $session->city : null);
        }

        if ($zone === null) {
            return;
        }

        $answers['timezone'] = $zone;
        $session->update(['answers' => $answers]);
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

    /**
     * @return list<array<string, mixed>>
     */
    private function requiredEventStops(Suggestion $suggestion): array
    {
        $ids = [];

        foreach ($suggestion->payload['event_ids'] ?? [] as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $events = $this->inventory->eventsByIds($ids);
        $stops = [];

        foreach ($ids as $id) {
            $event = $events->get($id);

            if (! $event instanceof Event) {
                continue;
            }

            $url = $this->eventLinks->urlFor($event);
            $stop = [
                'time' => $event->starts_at?->timezone((string) config('app.timezone'))->format('g:i A') ?? '',
                'name' => $event->title,
                'activity' => trim((string) ($event->description ?? '')) !== ''
                    ? mb_substr((string) $event->description, 0, 280)
                    : 'Go to this listing. Plan to arrive a little early, stay for the set or the main window, and use the listing URL for tickets or details.',
                'notes' => (string) ($event->venue_name ?? ''),
                'event_id' => $event->id,
            ];

            if ($url !== null) {
                $stop['venue_url'] = $url;
            }

            $stops[] = $stop;
        }

        return $stops;
    }

    private function hasDraft(Suggestion $suggestion): bool
    {
        return is_array($suggestion->itinerary_content) && $suggestion->itinerary_content !== [];
    }
}
