<?php

namespace Tests\Feature\Api\V1;

use App\Models\PageVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VisitApiTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function test_signed_in_pro_visit_stores_plan_and_browser(): void
    {
        $user = User::factory()->pro()->create([
            'name' => 'Pro Member',
            'email' => 'pro@plnr.test',
        ]);
        Sanctum::actingAs($user);

        $this->withHeader('User-Agent', self::CHROME_USER_AGENT)
            ->postJson('/api/v1/visits', [
                'path' => '/login?next=/plans',
                'referrer' => 'https://myplnr.app/',
                'language' => 'en-US',
                'timezone' => 'America/New_York',
                'screen' => '1440x900',
                'plan' => 'guest',
                'user_id' => 999,
            ])
            ->assertCreated()
            ->assertJsonPath('data.visit.plan', PageVisit::PLAN_PRO)
            ->assertJsonPath('data.visit.path', '/login')
            ->assertJsonPath('data.visit.browser', 'Chrome')
            ->assertJsonPath('data.visit.is_robot', false)
            ->assertJsonPath('data.visit.user.id', $user->id)
            ->assertJsonPath('data.visit.user.email', 'pro@plnr.test');

        $this->assertDatabaseHas('page_visits', [
            'user_id' => $user->id,
            'path' => '/login',
            'plan' => PageVisit::PLAN_PRO,
            'browser' => 'Chrome',
            'screen' => '1440x900',
            'is_robot' => false,
        ]);
    }

    public function test_guest_visit_has_no_user(): void
    {
        $this->withHeader('User-Agent', self::CHROME_USER_AGENT)
            ->postJson('/api/v1/visits', [
                'path' => '/',
                'plan' => 'pro',
            ])
            ->assertCreated()
            ->assertJsonPath('data.visit.plan', PageVisit::PLAN_GUEST)
            ->assertJsonPath('data.visit.user', null);

        $this->assertDatabaseHas('page_visits', [
            'user_id' => null,
            'path' => '/',
            'plan' => PageVisit::PLAN_GUEST,
        ]);
    }

    public function test_repeat_visit_within_thirty_seconds_is_ignored(): void
    {
        $this->withHeader('User-Agent', self::CHROME_USER_AGENT)
            ->postJson('/api/v1/visits', ['path' => '/privacy'])
            ->assertCreated();

        $this->withHeader('User-Agent', self::CHROME_USER_AGENT)
            ->postJson('/api/v1/visits', ['path' => '/privacy'])
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertSame(1, PageVisit::query()->count());
    }

    public function test_admin_pages_are_not_recorded(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/visits', ['path' => '/admin/users'])->assertOk();

        $this->assertSame(0, PageVisit::query()->count());
    }

    public function test_free_account_is_stored_as_free(): void
    {
        $user = User::factory()->create([
            'pro_status' => User::PRO_STATUS_INACTIVE,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/visits', ['path' => '/plans'])
            ->assertCreated()
            ->assertJsonPath('data.visit.plan', PageVisit::PLAN_FREE);
    }

    public function test_admin_can_list_and_filter_visits(): void
    {
        $admin = User::factory()->admin()->create();
        $pro = User::factory()->pro()->create(['email' => 'ada@plnr.test', 'name' => 'Ada']);
        PageVisit::query()->create([
            'user_id' => $pro->id,
            'occurred_at' => now(),
            'path' => '/plans',
            'ip_address' => '203.0.113.10',
            'browser' => 'Safari',
            'platform' => 'iOS',
            'device' => 'Apple iPhone',
            'device_type' => 'smartphone',
            'is_robot' => false,
            'plan' => PageVisit::PLAN_PRO,
        ]);
        PageVisit::query()->create([
            'user_id' => null,
            'occurred_at' => now()->subMinute(),
            'path' => '/privacy',
            'ip_address' => '203.0.113.11',
            'browser' => 'Chrome',
            'plan' => PageVisit::PLAN_GUEST,
            'is_robot' => false,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/visits?plan=pro&search=ada')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.visits.0.path', '/plans')
            ->assertJsonPath('data.visits.0.plan', PageVisit::PLAN_PRO)
            ->assertJsonPath('data.visits.0.user.email', 'ada@plnr.test')
            ->assertJsonPath('data.visits.0.ip_address', '203.0.113.10');

        $this->getJson('/api/v1/admin/visits?audience=guest')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.visits.0.plan', PageVisit::PLAN_GUEST)
            ->assertJsonPath('data.visits.0.user', null);

        $this->getJson('/api/v1/admin/stats')
            ->assertOk()
            ->assertJsonPath('data.pro_users_total', 1)
            ->assertJsonPath('data.visits_today', 2);
    }

    public function test_non_admin_cannot_list_visits(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/visits')->assertForbidden();
    }
}
