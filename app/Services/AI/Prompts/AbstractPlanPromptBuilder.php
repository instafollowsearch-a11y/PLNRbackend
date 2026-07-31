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

    /**
     * @param  array<string, mixed>  $stop
     * @return array<string, mixed>
     */
    protected function normalizeStop(array $stop): array
    {
        $normalized = [
            'time' => (string) ($stop['time'] ?? ''),
            'name' => (string) ($stop['name'] ?? ''),
            'activity' => (string) ($stop['activity'] ?? ''),
            'notes' => (string) ($stop['notes'] ?? ''),
        ];

        $venueUrl = $this->sanitizeHttpsUrl(
            $stop['venue_url'] ?? $stop['url'] ?? $stop['booking_url'] ?? null,
        );
        $mapsUrl = $this->sanitizeHttpsUrl($stop['maps_url'] ?? null);
        $externalUrl = $this->sanitizeHttpsUrl(
            $stop['external_url'] ?? $stop['link'] ?? $stop['website'] ?? null,
        );

        if ($venueUrl !== null) {
            $normalized['venue_url'] = $venueUrl;
        }

        if ($mapsUrl !== null) {
            $normalized['maps_url'] = $mapsUrl;
        }

        if ($externalUrl !== null) {
            $normalized['external_url'] = $externalUrl;
        }

        return $normalized;
    }

    protected function sanitizeHttpsUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme !== 'https') {
            return null;
        }

        return $url;
    }
}
