<?php

namespace App\Services\AI;

use App\Services\Settings\AppSettings;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AnthropicClient implements AiChatClient
{
    private const DEFAULT_MAX_TOKENS = 8192;

    private readonly AnthropicJsonDecoder $jsonDecoder;

    public function __construct(
        private readonly AppSettings $settings,
        ?AnthropicJsonDecoder $jsonDecoder = null,
    ) {
        $this->jsonDecoder = $jsonDecoder ?? new AnthropicJsonDecoder;
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function chat(array $messages, bool $jsonMode = true, ?int $maxTokens = null, array $options = []): array
    {
        // Claude suggestion/itinerary calls often exceed PHP's default 30s limit.
        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }

        $maxTokens ??= self::DEFAULT_MAX_TOKENS;
        $response = $this->request($messages, $jsonMode, $maxTokens, $options);
        $content = $this->extractTextContent($response['content'] ?? null);

        if ($content === '') {
            throw new RuntimeException('Anthropic returned an empty response.');
        }

        if (! $jsonMode) {
            return ['content' => $content];
        }

        try {
            return $this->jsonDecoder->decode($content);
        } catch (RuntimeException $exception) {
            $stopReason = is_string($response['stop_reason'] ?? null)
                ? $response['stop_reason']
                : null;

            Log::warning('anthropic.json_decode_failed', [
                'stop_reason' => $stopReason,
                'preview' => mb_substr($content, 0, 400),
            ]);

            // One repair pass — common for truncated itinerary JSON (max_tokens) or prose wrappers.
            $retryMessages = [
                ...$messages,
                [
                    'role' => 'assistant',
                    'content' => $content,
                ],
                [
                    'role' => 'user',
                    'content' => 'Your previous reply was not valid complete JSON. Reply again with ONLY the full JSON document, no markdown fences or commentary.',
                ],
            ];

            $retryMaxTokens = $stopReason === 'max_tokens'
                ? max($maxTokens, 12288)
                : $maxTokens;

            $retryResponse = $this->request($retryMessages, true, $retryMaxTokens, $options);
            $retryContent = $this->extractTextContent($retryResponse['content'] ?? null);

            if ($retryContent === '') {
                throw $exception;
            }

            try {
                return $this->jsonDecoder->decode($retryContent);
            } catch (RuntimeException) {
                throw $exception;
            }
        }
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function request(array $messages, bool $jsonMode, int $maxTokens, array $options = []): array
    {
        $apiKey = $this->settings->anthropicApiKey();

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

        $useWebSearch = (bool) ($options['web_search'] ?? false);

        $payload = [
            'model' => $this->settings->anthropicModel(),
            'max_tokens' => $maxTokens,
            'messages' => $anthropicMessages,
            'temperature' => $useWebSearch ? 0.4 : 0.7,
        ];

        if ($system !== null) {
            $payload['system'] = $jsonMode
                ? $system.' Respond with valid JSON only. Do not wrap the JSON in markdown code fences.'
                : $system;
        }

        if ($useWebSearch) {
            $tool = [
                'type' => (string) ($options['web_search_tool_type'] ?? 'web_search_20250305'),
                'name' => 'web_search',
                'max_uses' => max(1, (int) ($options['web_search_max_uses'] ?? 6)),
            ];

            $userLocation = $options['user_location'] ?? null;
            if (is_array($userLocation) && $userLocation !== []) {
                $tool['user_location'] = $userLocation;
            }

            $payload['tools'] = [$tool];
        }

        $timeout = $useWebSearch ? 180 : 120;

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
                ->timeout($timeout)
                ->post($this->settings->anthropicUrl(), $payload)
                ->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException(
                'Anthropic request failed: '.$exception->getMessage(),
                previous: $exception
            );
        }

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
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
