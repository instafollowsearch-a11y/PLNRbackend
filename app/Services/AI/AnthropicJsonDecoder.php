<?php

namespace App\Services\AI;

use RuntimeException;

/**
 * Robust JSON extraction for Anthropic chat responses (suggestions, itineraries, weekend picks).
 */
class AnthropicJsonDecoder
{
    /**
     * @return array<string, mixed>|list<mixed>
     */
    public function decode(string $content): array
    {
        $content = $this->normalize($content);

        if ($content === '') {
            throw new RuntimeException('Anthropic returned an empty response.');
        }

        $candidates = [$content];

        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/u', $content, $matches) === 1) {
            array_unshift($candidates, trim($matches[1]));
        }

        $extracted = $this->extractJsonDocument($content);
        if ($extracted !== null) {
            array_unshift($candidates, $extracted);
        }

        foreach ($candidates as $candidate) {
            $decoded = $this->tryDecode($candidate);
            if ($decoded !== null) {
                return $decoded;
            }

            $repaired = $this->repairTruncatedJson($candidate);
            if ($repaired !== null) {
                $decoded = $this->tryDecode($repaired);
                if ($decoded !== null) {
                    return $decoded;
                }
            }
        }

        throw new RuntimeException('Anthropic returned invalid JSON.');
    }

    private function normalize(string $content): string
    {
        $content = trim($content);
        $content = str_replace(
            ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"],
            ['"', '"', "'", "'"],
            $content,
        );

        return $content;
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function tryDecode(string $content): ?array
    {
        $content = trim($content);
        $content = (string) preg_replace('/,\s*([}\]])/', '$1', $content);

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function extractJsonDocument(string $content): ?string
    {
        $startObj = strpos($content, '{');
        $startArr = strpos($content, '[');

        if ($startObj === false && $startArr === false) {
            return null;
        }

        if ($startObj === false) {
            $start = $startArr;
            $opener = '[';
            $closer = ']';
        } elseif ($startArr === false) {
            $start = $startObj;
            $opener = '{';
            $closer = '}';
        } elseif ($startObj < $startArr) {
            $start = $startObj;
            $opener = '{';
            $closer = '}';
        } else {
            $start = $startArr;
            $opener = '[';
            $closer = ']';
        }

        $slice = substr($content, $start);
        $end = $this->findMatchingCloser($slice, $opener, $closer);

        if ($end === null) {
            return $slice;
        }

        return substr($slice, 0, $end + 1);
    }

    private function findMatchingCloser(string $slice, string $opener, string $closer): ?int
    {
        $depth = 0;
        $inString = false;
        $escape = false;
        $length = strlen($slice);

        for ($i = 0; $i < $length; $i++) {
            $char = $slice[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;

                    continue;
                }

                if ($char === '\\') {
                    $escape = true;

                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === $opener) {
                $depth++;

                continue;
            }

            if ($char === $closer) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function repairTruncatedJson(string $content): ?string
    {
        $content = trim($content);
        if ($content === '' || (! str_starts_with($content, '{') && ! str_starts_with($content, '['))) {
            return null;
        }

        // Drop a trailing incomplete string / key fragment.
        $content = (string) preg_replace('/,\s*"[^"]*$/', '', $content);
        $content = (string) preg_replace('/:\s*"[^"]*$/', ': ""', $content);
        $content = (string) preg_replace('/,\s*$/', '', $content);
        $content = (string) preg_replace('/,\s*([}\]])/', '$1', $content);

        $stack = [];
        $inString = false;
        $escape = false;
        $length = strlen($content);

        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;

                    continue;
                }

                if ($char === '\\') {
                    $escape = true;

                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '{' || $char === '[') {
                $stack[] = $char;

                continue;
            }

            if ($char === '}' || $char === ']') {
                $expected = $char === '}' ? '{' : '[';
                if ($stack !== [] && end($stack) === $expected) {
                    array_pop($stack);
                }
            }
        }

        if ($inString) {
            $content .= '"';
        }

        while ($stack !== []) {
            $open = array_pop($stack);
            $content .= $open === '{' ? '}' : ']';
        }

        return $content;
    }
}
