<?php

namespace App\Services\AI;

interface AiChatClient
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options  Optional flags (e.g. web_search, web_search_max_uses, user_location)
     * @return array<string, mixed>
     */
    public function chat(array $messages, bool $jsonMode = true, ?int $maxTokens = null, array $options = []): array;
}
