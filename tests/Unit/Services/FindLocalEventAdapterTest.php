<?php

namespace Tests\Unit\Services;

use App\Services\Events\Adapters\FindLocalEventAdapter;
use App\Services\Events\EventSourceResolver;
use App\Services\Events\FindLocalCredit;
use App\Services\Events\FindLocalMetros;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FindLocalEventAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.events.sources' => ['fixture'],
            'services.events.findlocal.api_key' => '',
        ]);
    }

    public function test_maps_findlocal_events_and_skips_midnight_and_deleted_rows(): void
    {
        config(['services.events.findlocal.api_key' => 'test-key']);
        Http::fake([
            'https://findlocal.community/api/events*' => Http::response(
                json_decode(
                    (string) file_get_contents(base_path('tests/Fixtures/findlocal/search_response.json')),
                    true,
                ),
            ),
        ]);

        $adapter = new FindLocalEventAdapter;
        $events = $adapter->fetch('Austin, TX');

        $this->assertCount(1, $events);
        $this->assertSame('findlocal', $events[0]->source);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $events[0]->externalId);
        $this->assertSame('Austin City Limits Music Festival', $events[0]->title);
        $this->assertSame('Austin, TX', $events[0]->city);
        $this->assertSame('Zilker Park', $events[0]->venueName);
        $this->assertSame('https://findlocal.community/event/acl', $events[0]->url);
        $this->assertSame(175.0, $events[0]->priceMin);
        $this->assertSame('2026-10-09 12:00:00', $events[0]->startsAt->format('Y-m-d H:i:s'));
        Http::assertSent(function ($request): bool {
            return $request['city'] === 'austin'
                && $request['when'] === 'week'
                && (int) $request['limit'] === 40;
        });

        $adapter->fetch('Austin');
        Http::assertSentCount(1);
    }

    public function test_unknown_city_makes_no_request(): void
    {
        config(['services.events.findlocal.api_key' => 'test-key']);
        Http::fake();

        $this->assertNull(FindLocalMetros::slugFor('Round Rock'));
        $this->assertSame([], (new FindLocalEventAdapter)->fetch('Round Rock'));
        Http::assertNothingSent();
    }

    public function test_missing_key_returns_nothing(): void
    {
        Http::fake();

        $this->assertSame([], (new FindLocalEventAdapter)->fetch('Austin'));
        Http::assertNothingSent();
        $this->assertSame([], FindLocalCredit::forCity('Austin'));
    }

    public function test_key_enables_the_source_without_changing_the_source_list(): void
    {
        $resolver = new EventSourceResolver;
        $sources = array_map(fn ($adapter) => $adapter->source(), $resolver->enabled());
        $this->assertNotContains('findlocal', $sources);

        config(['services.events.findlocal.api_key' => 'test-key']);
        $enabled = array_map(fn ($adapter) => $adapter->source(), $resolver->enabled());

        $this->assertContains('findlocal', $enabled);
        $this->assertContains('fixture', $enabled);
        $this->assertSame([], FindLocalCredit::forCity('NYC'));
        $this->assertSame([], FindLocalCredit::forCity('Round Rock'));
    }
}
