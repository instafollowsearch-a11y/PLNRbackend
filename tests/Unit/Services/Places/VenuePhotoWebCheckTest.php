<?php

namespace Tests\Unit\Services\Places;

use App\Services\AI\AiChatClient;
use App\Services\Places\PlaceWebSearch;
use App\Services\Places\VenuePhotoWebCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenuePhotoWebCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_batches_photo_lookups_into_two_direct_web_searches(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        $options = [];
        $this->app->instance(AiChatClient::class, new class($options) implements AiChatClient
        {
            public function __construct(private array &$options) {}

            public function chat(array $messages, bool $jsonMode = true, ?int $maxTokens = null, array $options = []): array
            {
                $this->options = $options;

                return [
                    'places' => [
                        ['name' => 'Quiet Bar', 'image_url' => 'https://example.com/quiet.jpg'],
                    ],
                ];
            }
        });

        $urls = app(VenuePhotoWebCheck::class)->imageUrls(['Quiet Bar', 'Late Club', 'Quiet Bar'], 'Austin');

        $this->assertSame(PlaceWebSearch::MAX_USES, $options['web_search_max_uses'] ?? null);
        $this->assertSame(['direct'], $options['web_search_allowed_callers'] ?? null);
        $this->assertSame(1, $options['web_search_pause_limit'] ?? null);
        $this->assertSame('https://example.com/quiet.jpg', $urls['quiet bar']);
    }
}
