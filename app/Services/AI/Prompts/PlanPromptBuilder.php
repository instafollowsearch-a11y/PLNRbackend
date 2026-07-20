<?php

namespace App\Services\AI\Prompts;

use App\Models\PlanSession;
use App\Models\Suggestion;

interface PlanPromptBuilder
{
    public function slug(): string;

    public function suggestionSystemPrompt(): string;

    public function suggestionUserPrompt(PlanSession $session): string;

    public function itinerarySystemPrompt(): string;

    public function itineraryUserPrompt(PlanSession $session, Suggestion $suggestion): string;

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function normalizeSuggestionPayload(array $item): array;

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    public function normalizeItineraryContent(array $response): array;
}
