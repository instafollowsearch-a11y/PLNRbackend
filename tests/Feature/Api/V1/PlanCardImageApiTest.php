<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanCardImageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\AppSettingsSeeder::class);
        Storage::fake('public');
    }

    public function test_public_plan_card_images_are_empty_until_an_admin_sets_one(): void
    {
        $this->getJson('/api/v1/plan-card-images')
            ->assertOk()
            ->assertJsonPath('data.images.date_night', null)
            ->assertJsonPath('data.images.road_trip', null);
    }

    public function test_admin_can_set_a_plan_card_image_url_and_reset_it(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/plan-card-images', [
            'plan_type' => 'vacation',
            'url' => 'https://images.example.com/beach.jpg',
        ])
            ->assertOk()
            ->assertJsonPath('data.images.vacation', 'https://images.example.com/beach.jpg');

        $this->getJson('/api/v1/plan-card-images')
            ->assertOk()
            ->assertJsonPath('data.images.vacation', 'https://images.example.com/beach.jpg')
            ->assertJsonPath('data.images.date_night', null);

        $this->deleteJson('/api/v1/admin/plan-card-images/vacation')
            ->assertOk()
            ->assertJsonPath('data.images.vacation', null);
    }

    public function test_admin_can_upload_a_plan_card_image_file(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->post('/api/v1/admin/plan-card-images', [
            'plan_type' => 'date_night',
            'image' => UploadedFile::fake()->image('dinner.jpg', 800, 500),
        ])->assertOk();

        $url = app(AppSettings::class)->publicPlanCardImageUrl('date_night', 'http://localhost');

        $this->assertNotNull($url);
        $this->assertStringContainsString('/api/v1/plan-card-images/date_night/file', (string) $url);
        Storage::disk('public')->assertExists('plan-cards/date_night.jpg');

        $this->get('/api/v1/plan-card-images/date_night/file')
            ->assertOk();
    }

    public function test_plan_card_image_requires_a_url_or_file(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/plan-card-images', [
            'plan_type' => 'night_out',
        ])->assertUnprocessable();
    }

    public function test_non_admin_cannot_change_plan_card_images(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/admin/plan-card-images', [
            'plan_type' => 'road_trip',
            'url' => 'https://images.example.com/road.jpg',
        ])->assertForbidden();
    }
}
