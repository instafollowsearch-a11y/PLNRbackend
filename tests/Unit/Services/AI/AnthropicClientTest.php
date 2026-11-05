<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\AnthropicClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_repair_does_not_search_the_web_again(): void
    {
        config([
            'services.anthropic.key' => 'test-key',
            'services.anthropic.url' => 'https://api.anthropic.com/v1/messages',
            'services.anthropic.model' => 'claude-test',
        ]);

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return Http::response([
                    'content' => [['type' => 'text', 'text' => 'not valid json at all']],
                    'stop_reason' => 'max_tokens',
                ]);
            }

            return Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => '{"recommendations":[{"title":"Show","venue":null,"starts_at":"2026-11-08T20:00:00Z","url":"https://example.com","source":"other"}]}',
                ]],
                'stop_reason' => 'end_turn',
            ]);
        });

        $result = app(AnthropicClient::class)->chat(
            [['role' => 'user', 'content' => 'scout']],
            true,
            2048,
            [
                'web_search' => true,
                'web_search_max_uses' => 2,
                'web_search_allowed_callers' => ['direct'],
            ],
        );

        $this->assertSame('Show', $result['recommendations'][0]['title']);
        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);
        $first = json_decode((string) $recorded[0][0]->body(), true);
        $second = json_decode((string) $recorded[1][0]->body(), true);
        $this->assertSame('web_search', $first['tools'][0]['name'] ?? null);
        $this->assertArrayNotHasKey('tools', $second);
    }

    public function test_pause_turn_continues_without_a_new_search_prompt(): void
    {
        config([
            'services.anthropic.key' => 'test-key',
            'services.anthropic.url' => 'https://api.anthropic.com/v1/messages',
            'services.anthropic.model' => 'claude-test',
        ]);

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return Http::response([
                    'content' => [['type' => 'server_tool_use', 'name' => 'web_search']],
                    'stop_reason' => 'pause_turn',
                ]);
            }

            return Http::response([
                'content' => [['type' => 'text', 'text' => '{"ok":true}']],
                'stop_reason' => 'end_turn',
            ]);
        });

        $result = app(AnthropicClient::class)->chat(
            [['role' => 'user', 'content' => 'scout']],
            true,
            2048,
            ['web_search' => true, 'web_search_max_uses' => 2],
        );

        $this->assertTrue($result['ok']);
        $this->assertCount(2, Http::recorded());
        $second = json_decode((string) Http::recorded()[1][0]->body(), true);
        $this->assertSame('assistant', $second['messages'][1]['role'] ?? null);
    }

    public function test_place_lookups_cap_pause_turn_at_one_continue(): void
    {
        config([
            'services.anthropic.key' => 'test-key',
            'services.anthropic.url' => 'https://api.anthropic.com/v1/messages',
            'services.anthropic.model' => 'claude-test',
        ]);

        Http::fake(function () {
            return Http::response([
                'content' => [['type' => 'text', 'text' => '{"ok":true}']],
                'stop_reason' => 'pause_turn',
            ]);
        });

        $result = app(AnthropicClient::class)->chat(
            [['role' => 'user', 'content' => 'hours']],
            true,
            2048,
            ['web_search' => true, 'web_search_pause_limit' => 1],
        );

        $this->assertTrue($result['ok']);
        $this->assertCount(2, Http::recorded());
        $second = json_decode((string) Http::recorded()[1][0]->body(), true);
        $this->assertSame('assistant', $second['messages'][1]['role'] ?? null);
    }
}
