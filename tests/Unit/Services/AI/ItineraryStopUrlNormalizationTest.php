<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\Prompts\NightOutPromptBuilder;
use Tests\TestCase;

class ItineraryStopUrlNormalizationTest extends TestCase
{
    public function test_night_out_normalizer_keeps_https_urls_and_drops_invalid(): void
    {
        $builder = new NightOutPromptBuilder;

        $content = $builder->normalizeItineraryContent([
            'title' => 'Evening Out',
            'summary' => 'Fun night',
            'stops' => [
                [
                    'time' => '8:00 PM',
                    'name' => 'Jazz Club',
                    'activity' => 'Live music',
                    'notes' => 'Book ahead',
                    'venue_url' => 'https://example.com/jazz',
                    'maps_url' => 'https://maps.example.com/jazz',
                    'external_url' => 'http://insecure.example.com',
                    'url' => 'not-a-url',
                ],
            ],
        ]);

        $this->assertSame('https://example.com/jazz', $content['stops'][0]['venue_url']);
        $this->assertSame('https://maps.example.com/jazz', $content['stops'][0]['maps_url']);
        $this->assertArrayNotHasKey('external_url', $content['stops'][0]);
    }
}
