<?php

namespace App\Services\AI;

interface AiChatClient
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<string, mixed>
     */
    public function chat(array $messages, bool $jsonMode = true): array;
}
