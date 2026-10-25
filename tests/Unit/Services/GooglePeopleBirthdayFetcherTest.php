<?php

namespace Tests\Unit\Services;

use App\Services\Auth\GooglePeopleBirthdayFetcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GooglePeopleBirthdayFetcherTest extends TestCase
{
    public function test_fetch_returns_null_without_access_token(): void
    {
        Http::fake();

        $this->assertNull((new GooglePeopleBirthdayFetcher)->fetch(null));
        $this->assertNull((new GooglePeopleBirthdayFetcher)->fetch(''));
        Http::assertNothingSent();
    }

    public function test_fetch_returns_primary_account_birthday(): void
    {
        Http::fake([
            'people.googleapis.com/*' => Http::response([
                'birthdays' => [
                    [
                        'metadata' => [
                            'primary' => true,
                            'source' => ['type' => 'ACCOUNT'],
                        ],
                        'date' => [
                            'year' => 1994,
                            'month' => 7,
                            'day' => 4,
                        ],
                    ],
                ],
            ]),
        ]);

        $this->assertSame(
            '1994-07-04',
            (new GooglePeopleBirthdayFetcher)->fetch('access-token'),
        );
    }

    public function test_fetch_ignores_birthday_without_year(): void
    {
        Http::fake([
            'people.googleapis.com/*' => Http::response([
                'birthdays' => [
                    [
                        'metadata' => ['primary' => true],
                        'date' => [
                            'month' => 7,
                            'day' => 4,
                        ],
                    ],
                ],
            ]),
        ]);

        $this->assertNull((new GooglePeopleBirthdayFetcher)->fetch('access-token'));
    }
}
