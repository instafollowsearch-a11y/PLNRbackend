<?php

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Services\Events\EventIngestionService;
use App\Services\Events\EventSourceResolver;
use App\Services\Events\NormalizedEvent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_is_idempotent_for_same_source_and_external_id(): void
    {
        $service = new EventIngestionService;

        $normalized = new NormalizedEvent(
            source: 'fixture',
            externalId: 'event-1',
            title: 'Original Title',
            description: 'Desc',
            city: 'Austin',
            venueName: 'Venue',
            startsAt: Carbon::parse('2026-06-14T20:00:00-05:00'),
            endsAt: null,
            url: 'https://example.com',
            imageUrl: null,
            priceMin: 20,
            priceMax: 40,
            payload: ['id' => 'event-1'],
        );

        $service->upsert($normalized);
        $normalizedUpdated = new NormalizedEvent(
            source: 'fixture',
            externalId: 'event-1',
            title: 'Updated Title',
            description: 'Desc',
            city: 'Austin',
            venueName: 'Venue',
            startsAt: Carbon::parse('2026-06-14T20:00:00-05:00'),
            endsAt: null,
            url: 'https://example.com',
            imageUrl: null,
            priceMin: 20,
            priceMax: 40,
            payload: ['id' => 'event-1'],
        );

        $service->upsert($normalizedUpdated);

        $this->assertSame(1, Event::query()->count());
        $this->assertSame('Updated Title', Event::query()->first()?->title);
    }

    public function test_sync_city_imports_fixture_events(): void
    {
        config([
            'services.events.fixtures_path' => base_path('data/events'),
            'services.events.sources' => ['fixture'],
        ]);

        $service = new EventIngestionService;
        $resolver = new EventSourceResolver;

        $count = $service->syncCity('Austin', $resolver);

        $this->assertGreaterThanOrEqual(3, $count);
        $this->assertGreaterThanOrEqual(3, Event::query()->where('city', 'Austin')->count());
    }
}
