<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\AnthropicJsonDecoder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AnthropicJsonDecoderTest extends TestCase
{
    public function test_decodes_clean_object(): void
    {
        $decoded = (new AnthropicJsonDecoder)->decode('{"title":"Night Out","stops":[{"time":"8pm","name":"Bar"}]}');

        $this->assertSame('Night Out', $decoded['title']);
        $this->assertCount(1, $decoded['stops']);
    }

    public function test_strips_markdown_fence_and_prose(): void
    {
        $raw = <<<'TXT'
Here you go:
```json
{"title":"Date","summary":"fun","stops":[{"time":"7pm","name":"Dinner","activity":"eat","notes":"book ahead"}]}
```
TXT;

        $decoded = (new AnthropicJsonDecoder)->decode($raw);

        $this->assertSame('Date', $decoded['title']);
    }

    public function test_repairs_truncated_object(): void
    {
        $raw = '{"title":"Trip","summary":"go","stops":[{"time":"9am","name":"Cafe","activity":"coffee","notes":"nice"';

        $decoded = (new AnthropicJsonDecoder)->decode($raw);

        $this->assertSame('Trip', $decoded['title']);
        $this->assertCount(1, $decoded['stops']);
    }

    public function test_removes_trailing_commas(): void
    {
        $raw = '{"title":"Out","stops":[{"time":"8pm","name":"Club",},],}';

        $decoded = (new AnthropicJsonDecoder)->decode($raw);

        $this->assertSame('Out', $decoded['title']);
    }

    public function test_throws_on_non_json(): void
    {
        $this->expectException(RuntimeException::class);

        (new AnthropicJsonDecoder)->decode('sorry I cannot help with that');
    }
}
