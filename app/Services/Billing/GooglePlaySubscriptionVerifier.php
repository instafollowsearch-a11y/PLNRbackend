<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\Http;

class GooglePlaySubscriptionVerifier implements PlaySubscriptionVerifier
{
    public function verify(string $productId, string $purchaseToken): PlaySubscriptionReceipt
    {
        $account = $this->serviceAccount();
        $packageName = (string) config('services.google_play.package_name');
        $accessToken = $this->accessToken($account);
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->get($this->subscriptionUrl($packageName, $purchaseToken));

        if (! $response->successful()) {
            throw new PlayBillingException('This Google Play purchase could not be verified.');
        }

        $payload = $response->json();
        $lineItems = is_array($payload) ? ($payload['lineItems'] ?? []) : [];
        $matching = $this->matchingLineItem(is_array($lineItems) ? $lineItems : [], $productId);

        if ($matching === null) {
            return new PlaySubscriptionReceipt(false, null);
        }

        $state = (string) ($payload['subscriptionState'] ?? '');
        $expiry = isset($matching['expiryTime']) ? strtotime((string) $matching['expiryTime']) : false;
        $expiresAt = $expiry === false ? null : now()->setTimestamp($expiry);

        return new PlaySubscriptionReceipt($this->entitled($state, $expiresAt), $expiresAt);
    }

    public function acknowledge(string $productId, string $purchaseToken): void
    {
        $account = $this->serviceAccount();
        $packageName = (string) config('services.google_play.package_name');
        $accessToken = $this->accessToken($account);
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->asJson()
            ->post($this->acknowledgeUrl($packageName, $productId, $purchaseToken), []);

        if ($response->successful() || $this->alreadyAcknowledged($response->status(), (string) $response->body())) {
            return;
        }

        throw new PlayBillingException('This Google Play purchase could not be verified.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceAccount(): array
    {
        $configured = config('services.google_play.service_account_json');
        if (! is_string($configured) || trim($configured) === '') {
            throw new PlayBillingException('Google Play billing is not configured yet.');
        }

        $json = str_starts_with(trim($configured), '{')
            ? trim($configured)
            : (is_file($configured) ? (string) file_get_contents($configured) : '');
        $account = json_decode($json, true);

        if (! is_array($account) || ! isset($account['client_email'], $account['private_key'])) {
            throw new PlayBillingException('Google Play billing is not configured yet.');
        }

        return $account;
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function accessToken(array $account): string
    {
        $now = time();
        $assertion = $this->signJwt($account, $now);
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new PlayBillingException('This Google Play purchase could not be verified.');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function signJwt(array $account, int $now): string
    {
        $header = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = $this->base64Url((string) json_encode([
            'iss' => $account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/androidpublisher',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $unsigned = $header.'.'.$claim;
        $signature = '';
        $signed = openssl_sign($unsigned, $signature, (string) $account['private_key'], OPENSSL_ALGO_SHA256);

        if ($signed !== true) {
            throw new PlayBillingException('Google Play billing is not configured yet.');
        }

        return $unsigned.'.'.$this->base64Url($signature);
    }

    /**
     * @param  list<mixed>  $lineItems
     * @return array<string, mixed>|null
     */
    private function matchingLineItem(array $lineItems, string $productId): ?array
    {
        foreach ($lineItems as $item) {
            if (is_array($item) && ($item['productId'] ?? null) === $productId) {
                return $item;
            }
        }

        return null;
    }

    private function entitled(string $state, mixed $expiresAt): bool
    {
        if (in_array($state, ['SUBSCRIPTION_STATE_ACTIVE', 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD'], true)) {
            return true;
        }

        return $state === 'SUBSCRIPTION_STATE_CANCELED'
            && $expiresAt instanceof \DateTimeInterface
            && now()->lt($expiresAt);
    }

    private function alreadyAcknowledged(int $status, string $body): bool
    {
        return $status === 400 && str_contains(strtolower($body), 'already');
    }

    private function subscriptionUrl(string $packageName, string $purchaseToken): string
    {
        return 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/'
            .rawurlencode($packageName)
            .'/purchases/subscriptionsv2/tokens/'
            .rawurlencode($purchaseToken);
    }

    private function acknowledgeUrl(string $packageName, string $productId, string $purchaseToken): string
    {
        return 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/'
            .rawurlencode($packageName)
            .'/purchases/subscriptions/'
            .rawurlencode($productId)
            .'/tokens/'
            .rawurlencode($purchaseToken)
            .':acknowledge';
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
