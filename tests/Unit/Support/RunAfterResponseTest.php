<?php

namespace Tests\Unit\Support;

use App\Support\Http\RunAfterResponse;
use Tests\TestCase;

class RunAfterResponseTest extends TestCase
{
    public function test_inline_tests_run_work_immediately(): void
    {
        $ran = false;
        $accepted = RunAfterResponse::defer(function () use (&$ran): void {
            $ran = true;
        });

        $this->assertTrue($ran);
        $this->assertNull($accepted);
    }

    public function test_deferred_work_returns_generating_without_running_inline(): void
    {
        config(['app.defer_http_work' => true]);
        $ran = false;
        $accepted = RunAfterResponse::defer(function () use (&$ran): void {
            $ran = true;
        });

        $this->assertFalse($ran);
        $this->assertNotNull($accepted);
        $this->assertSame(202, $accepted->getStatusCode());
        $this->assertSame('generating', $accepted->getData(true)['data']['status'] ?? null);
    }

    public function test_after_flush_does_not_run_during_inline_tests(): void
    {
        $ran = false;
        RunAfterResponse::afterFlush(function () use (&$ran): void {
            $ran = true;
        });

        $this->assertFalse($ran);
    }
}
