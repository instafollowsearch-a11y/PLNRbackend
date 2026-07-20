<?php

namespace Tests\Feature\Api\V1;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $webhookSecret = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.stripe.webhook_secret', $this->webhookSecret);
    }

    public function test_payment_intent_succeeded_confirms_booking(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->create([
            'user_id' => $user->id,
            'status' => Booking::STATUS_PENDING,
            'stripe_payment_intent_id' => 'pi_test_123',
        ]);

        $payload = json_encode([
            'id' => 'evt_test_1',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test_123',
                    'metadata' => [
                        'booking_uuid' => $booking->uuid,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->postWebhook($payload)->assertOk()->assertJson(['received' => true]);

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->fresh()->status);
    }

    public function test_payment_intent_failed_marks_booking_failed(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->create([
            'user_id' => $user->id,
            'status' => Booking::STATUS_PENDING,
            'stripe_payment_intent_id' => 'pi_test_456',
        ]);

        $payload = json_encode([
            'id' => 'evt_test_2',
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => [
                    'id' => 'pi_test_456',
                    'metadata' => [
                        'booking_uuid' => $booking->uuid,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->postWebhook($payload)->assertOk();

        $this->assertSame(Booking::STATUS_FAILED, $booking->fresh()->status);
    }

    public function test_invalid_signature_returns_400(): void
    {
        $payload = json_encode(['id' => 'evt_bad'], JSON_THROW_ON_ERROR);

        $this->postJson('/api/v1/stripe/webhook', json_decode($payload, true), [
            'Stripe-Signature' => 'invalid',
        ])->assertStatus(400);
    }

    private function postWebhook(string $payload): \Illuminate\Testing\TestResponse
    {
        $timestamp = time();
        $signedPayload = "{$timestamp}.{$payload}";
        $signature = hash_hmac('sha256', $signedPayload, $this->webhookSecret);
        $header = "t={$timestamp},v1={$signature}";

        return $this->call(
            'POST',
            '/api/v1/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => $header,
            ],
            $payload,
        );
    }
}
