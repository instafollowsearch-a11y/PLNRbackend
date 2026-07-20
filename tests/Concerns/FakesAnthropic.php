<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

trait FakesAnthropic
{
    /**
     * @return array<string, mixed>
     */
    protected function anthropicResponseFromOpenAiFixture(string $filename): array
    {
        $openai = json_decode(
            file_get_contents(base_path("tests/Fixtures/openai/{$filename}")),
            true,
        );

        $text = $openai['choices'][0]['message']['content'] ?? '';

        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-test',
            'stop_reason' => 'end_turn',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $text,
                ],
            ],
        ];
    }

    protected function fakeAnthropicSuggestions(): void
    {
        $response = $this->anthropicResponseFromOpenAiFixture('suggestions_response.json');

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response($response),
            config('services.anthropic.url') => Http::response($response),
        ]);
    }

    protected function fakeAnthropicItinerary(): void
    {
        $response = $this->anthropicResponseFromOpenAiFixture('itinerary_response.json');

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response($response),
            config('services.anthropic.url') => Http::response($response),
        ]);
    }
}
