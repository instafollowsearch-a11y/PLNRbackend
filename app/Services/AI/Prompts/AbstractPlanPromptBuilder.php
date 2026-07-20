<?php

namespace App\Services\AI\Prompts;

use App\Models\PlanSession;
use App\Models\Suggestion;
use App\Services\Events\EventContextService;

abstract class AbstractPlanPromptBuilder implements PlanPromptBuilder
{
    protected function appendRefinementMessages(PlanSession $session, array $lines): array
    {
        if (! empty($session->refinement_messages)) {
            $lines[] = 'User refinement requests:';
            foreach ($session->refinement_messages as $message) {
                if (($message['role'] ?? '') === 'user') {
                    $lines[] = '- '.($message['content'] ?? '');
                }
            }
        }

        return $lines;
    }

    protected function formatAnswers(PlanSession $session): string
    {
        $answers = $session->answers ?? [];
        $lines = [];

        foreach ($answers as $key => $value) {
            if (is_scalar($value)) {
                $lines[] = ucfirst(str_replace('_', ' ', (string) $key)).': '.$value;
            }
        }

        return implode("\n", $lines);
    }

    protected function appendLocalEventsContext(PlanSession $session, array $lines): array
    {
        $city = $session->city ?? ($session->answers['city'] ?? null);
        $context = app(EventContextService::class)->formatForPrompt(is_string($city) ? $city : null);

        if ($context !== '') {
            $lines[] = $context;
        }

        return $lines;
    }

    protected function formatSuggestionContext(Suggestion $suggestion): string
    {
        $payload = $suggestion->payload ?? [];

        return implode("\n", [
            'Selected option: '.($payload['name'] ?? ''),
            'Description: '.($payload['description'] ?? ''),
            'Payload: '.json_encode($payload),
        ]);
    }
}
