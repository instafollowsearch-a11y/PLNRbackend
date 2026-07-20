<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SyncEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_syncs_fixture_and_user_profile_cities(): void
    {
        config([
            'services.events.fixtures_path' => base_path('data/events'),
            'services.events.sources' => ['fixture', 'partiful', 'posh'],
        ]);

        User::factory()->create(['city' => 'Austin']);

        Artisan::call('events:sync');

        $this->assertGreaterThan(0, \App\Models\Event::query()->where('city', 'Austin')->count());
    }
}
