<?php

namespace Tests\Unit\Services\Places;

use App\Services\AI\AiChatClient;
use App\Services\Places\OpeningHoursWebCheck;
use App\Services\Places\PlaceWebSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpeningHoursWebCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_batches_places_into_two_direct_web_searches(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        $options = [];
        $this->app->instance(AiChatClient::class, new class($options) implements AiChatClient
        {
            public function __construct(private array &$options) {}

            public function chat(array $messages, bool $jsonMode = true, ?int $maxTokens = null, array $options = []): array
            {
                $this->options = $options;
                $this->options['system'] = $messages[0]['content'] ?? '';

                return [
                    'places' => [
                        ['name' => 'Quiet Bar', 'hours' => 'Friday: 6 PM–2 AM'],
                        ['name' => 'Late Club', 'hours' => 'Friday: 9 PM–4 AM'],
                    ],
                ];
            }
        });

        $stops = app(OpeningHoursWebCheck::class)->fill([
            ['name' => 'Quiet Bar', 'hours' => ''],
            ['name' => 'Late Club', 'hours' => ''],
            ['name' => 'Quiet Bar', 'hours' => ''],
        ], 'Austin');

        $this->assertSame(PlaceWebSearch::MAX_USES, $options['web_search_max_uses'] ?? null);
        $this->assertSame(['direct'], $options['web_search_allowed_callers'] ?? null);
        $this->assertSame(1, $options['web_search_pause_limit'] ?? null);
        $this->assertStringContainsString('one query', (string) ($options['system'] ?? ''));
        $this->assertStringContainsString('photograph', (string) ($options['system'] ?? ''));
        $this->assertStringNotContainsString('similar businesses', (string) ($options['system'] ?? ''));
        $this->assertSame('Friday: 6 PM–2 AM', $stops[0]['hours']);
        $this->assertSame('Friday: 9 PM–4 AM', $stops[1]['hours']);
    }

    public function test_cached_hours_skip_a_second_web_search(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        $calls = 0;
        $this->app->instance(AiChatClient::class, new class($calls) implements AiChatClient
        {
            public function __construct(private int &$calls) {}

            public function chat(array $messages, bool $jsonMode = true, ?int $maxTokens = null, array $options = []): array
            {
                $this->calls++;

                return [
                    'places' => [
                        ['name' => 'Quiet Bar', 'hours' => 'Friday: 6 PM–2 AM'],
                    ],
                ];
            }
        });

        $checker = app(OpeningHoursWebCheck::class);
        $checker->fill([['name' => 'Quiet Bar', 'hours' => '']], 'Austin');
        $again = $checker->fill([['name' => 'Quiet Bar', 'hours' => '']], 'Austin');

        $this->assertSame(1, $calls);
        $this->assertSame('Friday: 6 PM–2 AM', $again[0]['hours']);
    }
}
