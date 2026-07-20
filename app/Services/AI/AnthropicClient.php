<?php

namespace App\Services\AI;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicClient implements AiChatClient
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<string, mixed>
     */
    public function chat(array $messages, bool $jsonMode = true): array
    {
        // Claude suggestion/itinerary calls often exceed PHP's default 30s limit.
        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }

        $apiKey = config('services.anthropic.key');

        if (empty($apiKey)) {
            throw new RuntimeException('Anthropic API key is not configured.');
        }

        $system = null;
        $anthropicMessages = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? '';
            $content = $message['content'] ?? '';

            if ($role === 'system') {
                $system = $content;

                continue;
            }

            if ($role === 'assistant' || $role === 'user') {
                $anthropicMessages[] = [
                    'role' => $role,
                    'content' => $content,
                ];
            }
        }

        $payload = [
            'model' => config('services.anthropic.model'),
            'max_tokens' => 4096,
            'messages' => $anthropicMessages,
            'temperature' => 0.7,
        ];

        if ($system !== null) {
            $payload['system'] = $jsonMode
                ? $system.' Respond with valid JSON only. Do not wrap the JSON in markdown code fences.'
                : $system;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
                ->timeout(90)
                ->post(config('services.anthropic.url'), $payload)
                ->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException(
                'Anthropic request failed: '.$exception->getMessage(),
                previous: $exception
            );
        }

        $content = $this->extractTextContent($response->json('content'));

        if ($content === '') {
            throw new RuntimeException('Anthropic returned an empty response.');
        }

        if (! $jsonMode) {
            return ['content' => $content];
        }

        return $this->decodeJsonContent($content);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonContent(string $content): array
    {
        $content = trim($content);

        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/u', $content, $matches) === 1) {
            $content = trim($matches[1]);
        }

        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $startObj = strpos($content, '{');
        $startArr = strpos($content, '[');

        if ($startObj === false && $startArr === false) {
            throw new RuntimeException('Anthropic returned invalid JSON.');
        }

        if ($startObj === false) {
            $start = $startArr;
            $end = strrpos($content, ']');
        } elseif ($startArr === false) {
            $start = $startObj;
            $end = strrpos($content, '}');
        } else {
            $start = min($startObj, $startArr);
            $end = $start === $startObj ? strrpos($content, '}') : strrpos($content, ']');
        }

        if ($end === false || $end <= $start) {
            throw new RuntimeException('Anthropic returned invalid JSON.');
        }

        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Anthropic returned invalid JSON.');
        }

        return $decoded;
    }

    /**
     * @param  mixed  $contentBlocks
     */
    private function extractTextContent(mixed $contentBlocks): string
    {
        if (! is_array($contentBlocks)) {
            return '';
        }

        $parts = [];

        foreach ($contentBlocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return trim(implode("\n", $parts));
    }
}
