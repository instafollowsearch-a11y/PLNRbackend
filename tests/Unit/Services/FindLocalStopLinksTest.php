<?php

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Services\Events\FindLocalStopLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FindLocalStopLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_attaches_the_find_local_event_page_when_a_stop_names_the_event(): void
    {
        Event::factory()->create([
            'source' => 'findlocal',
            'external_id' => '11111111-1111-1111-1111-111111111111',
            'title' => 'Sarah Sharp Quintet',
            'city' => 'Austin, TX',
            'url' => 'https://tickets.example.com/sarah',
            'starts_at' => now()->addDay(),
        ]);

        $content = app(FindLocalStopLinks::class)->attach([
            'title' => 'Night out',
            'stops' => [
                ['time' => '8:00 PM', 'name' => 'Dinner', 'activity' => 'Eat'],
                ['time' => '9:30 PM', 'name' => 'Sarah Sharp Quintet', 'activity' => 'Live jazz'],
            ],
            'days' => [
                [
                    'date' => 'Saturday',
                    'stops' => [
                        ['time' => '9:30 PM', 'name' => 'See the Sarah Sharp Quintet downtown', 'activity' => 'Music'],
                    ],
                ],
            ],
        ], 'Austin, TX');

        $this->assertArrayNotHasKey('findlocal_url', $content['stops'][0]);
        $this->assertSame(
            'https://findlocal.community/event/11111111-1111-1111-1111-111111111111',
            $content['stops'][1]['findlocal_url'],
        );
        $this->assertSame(
            'https://findlocal.community/event/11111111-1111-1111-1111-111111111111',
            $content['days'][0]['stops'][0]['findlocal_url'],
        );
    }

    public function test_leaves_stops_alone_when_the_city_has_no_find_local_events(): void
    {
        $content = [
            'stops' => [
                ['name' => 'Sarah Sharp Quintet'],
            ],
        ];

        $this->assertSame($content, app(FindLocalStopLinks::class)->attach($content, 'Andrews County, Texas'));
    }
}
