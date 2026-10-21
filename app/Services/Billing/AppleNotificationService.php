<?php

namespace App\Services\Billing;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class AppleNotificationService
{
    /** @var list<string> */
    private const ACTIVATE_TYPES = [
        'SUBSCRIBED',
        'DID_RENEW',
        'OFFER_REDEEMED',
        'DID_CHANGE_RENEWAL_PREF',
    ];

    /** @var list<string> */
    private const DEACTIVATE_TYPES = [
        'EXPIRED',
        'GRACE_PERIOD_EXPIRED',
        'REVOKE',
        'REFUND',
    ];

    public function __construct(
        private readonly AppleJwsVerifier $jws,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $signedPayload = $payload['signedPayload'] ?? null;
        if (! is_string($signedPayload) || $signedPayload === '') {
            throw new AppleBillingException('Invalid App Store notification payload.');
        }

        $notification = $this->jws->decode($signedPayload);
        if ($notification === null) {
            throw new AppleBillingException('Invalid App Store notification signature.');
        }

        $type = (string) ($notification['notificationType'] ?? '');
        $data = is_array($notification['data'] ?? null) ? $notification['data'] : [];
        $signedTransaction = (string) ($data['signedTransactionInfo'] ?? '');
        if ($signedTransaction === '') {
            Log::info('apple.iap.notification.ignored', [
                'notification_type' => $type,
                'reason' => 'missing_transaction',
            ]);

            return;
        }

        $transaction = $this->jws->decode($signedTransaction);
        if ($transaction === null) {
            throw new AppleBillingException('Invalid App Store transaction signature.');
        }

        $bundleId = (string) config('services.apple.bundle_id');
        if (($transaction['bundleId'] ?? null) !== $bundleId) {
            Log::warning('apple.iap.notification.bundle_mismatch', [
                'notification_type' => $type,
            ]);

            return;
        }

        $originalTransactionId = isset($transaction['originalTransactionId'])
            ? (string) $transaction['originalTransactionId']
            : '';
        if ($originalTransactionId === '') {
            return;
        }

        $user = User::query()
            ->where('apple_original_transaction_id', $originalTransactionId)
            ->first();
        if ($user === null) {
            Log::info('apple.iap.notification.unknown_transaction', [
                'notification_type' => $type,
            ]);

            return;
        }

        if (in_array($type, self::DEACTIVATE_TYPES, true)) {
            $this->deactivate($user, $type);

            return;
        }

        if ($type === 'DID_FAIL_TO_RENEW') {
            $expiresAt = $this->expiresAt($transaction['expiresDate'] ?? null);
            if ($expiresAt !== null && now()->lt($expiresAt)) {
                $user->forceFill([
                    'pro_status' => User::PRO_STATUS_PAST_DUE,
                    'pro_current_period_end' => $expiresAt,
                ])->save();
                Log::info('apple.iap.notification.applied', [
                    'notification_type' => $type,
                    'outcome' => 'past_due',
                ]);

                return;
            }

            $this->deactivate($user, $type);

            return;
        }

        if (! in_array($type, self::ACTIVATE_TYPES, true)) {
            Log::info('apple.iap.notification.ignored', [
                'notification_type' => $type,
                'reason' => 'unhandled_type',
            ]);

            return;
        }

        $productId = (string) config('services.apple.product_id');
        if (($transaction['productId'] ?? null) !== $productId) {
            Log::warning('apple.iap.notification.product_mismatch', [
                'notification_type' => $type,
            ]);

            return;
        }

        $expiresAt = $this->expiresAt($transaction['expiresDate'] ?? null);
        if ($expiresAt === null || ! now()->lt($expiresAt)) {
            Log::info('apple.iap.notification.ignored', [
                'notification_type' => $type,
                'reason' => 'not_entitled',
            ]);

            return;
        }

        $user->forceFill([
            'pro_status' => User::PRO_STATUS_ACTIVE,
            'apple_product_id' => $productId,
            'apple_original_transaction_id' => $originalTransactionId,
            'pro_current_period_end' => $expiresAt,
        ])->save();

        Log::info('apple.iap.notification.applied', [
            'notification_type' => $type,
            'outcome' => 'active',
        ]);
    }

    private function deactivate(User $user, string $type): void
    {
        $user->forceFill([
            'pro_status' => User::PRO_STATUS_CANCELED,
            'pro_current_period_end' => now(),
        ])->save();

        Log::info('apple.iap.notification.applied', [
            'notification_type' => $type,
            'outcome' => 'canceled',
        ]);
    }

    private function expiresAt(mixed $expiresDate): ?\DateTimeInterface
    {
        if (! is_numeric($expiresDate)) {
            return null;
        }

        $millis = (int) $expiresDate;
        if ($millis <= 0) {
            return null;
        }

        return now()->setTimestamp((int) floor($millis / 1000));
    }
}
