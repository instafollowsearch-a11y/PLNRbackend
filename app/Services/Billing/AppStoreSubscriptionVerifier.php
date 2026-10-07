<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AppStoreSubscriptionVerifier implements AppleSubscriptionVerifier
{
    private const PRODUCTION = 'https://api.storekit.apple.com';

    private const SANDBOX = 'https://api.storekit-sandbox.apple.com';

    public function verify(string $productId, string $transactionId): AppleSubscriptionReceipt
    {
        $token = $this->token();
        $response = $this->fetch(self::PRODUCTION, $token, $transactionId);

        if ($this->transactionMissing($response)) {
            $response = $this->fetch(self::SANDBOX, $token, $transactionId);
        }

        if (! $response->successful()) {
            throw new AppleBillingException('This App Store purchase could not be verified.');
        }

        return $this->receipt($response->json(), $productId);
    }

    private function fetch(string $origin, string $token, string $transactionId): Response
    {
        return Http::withToken($token)
            ->acceptJson()
            ->get($origin.'/inApps/v1/subscriptions/'.rawurlencode($transactionId));
    }

    private function transactionMissing(Response $response): bool
    {
        if ($response->status() === 404) {
            return true;
        }

        return (int) $response->json('errorCode') === 4040010;
    }

    private function receipt(mixed $payload, string $productId): AppleSubscriptionReceipt
    {
        $groups = is_array($payload) ? ($payload['data'] ?? []) : [];
        $bundleId = (string) config('services.apple.bundle_id');

        if (! is_array($groups)) {
            return new AppleSubscriptionReceipt(false, null, null);
        }

        foreach ($groups as $group) {
            $transactions = is_array($group) ? ($group['lastTransactions'] ?? []) : [];
            if (! is_array($transactions)) {
                continue;
            }

            foreach ($transactions as $transaction) {
                if (! is_array($transaction)) {
                    continue;
                }

                $signed = $this->decodeJws((string) ($transaction['signedTransactionInfo'] ?? ''));
                if ($signed === null) {
                    continue;
                }

                if (($signed['productId'] ?? null) !== $productId || ($signed['bundleId'] ?? null) !== $bundleId) {
                    continue;
                }

                $expiresAt = $this->expiresAt($signed['expiresDate'] ?? null);
                $original = isset($signed['originalTransactionId']) ? (string) $signed['originalTransactionId'] : null;
                $active = $this->entitled((int) ($transaction['status'] ?? 0), $expiresAt);

                if ($active) {
                    return new AppleSubscriptionReceipt(true, $expiresAt, $original);
                }
            }
        }

        return new AppleSubscriptionReceipt(false, null, null);
    }

    private function entitled(int $status, ?\DateTimeInterface $expiresAt): bool
    {
        if (! in_array($status, [1, 3, 4], true)) {
            return false;
        }

        return $expiresAt !== null && now()->lt($expiresAt);
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

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJws(string $jws): ?array
    {
        $parts = explode('.', $jws);
        if (count($parts) < 2) {
            return null;
        }

        $json = base64_decode($this->padBase64(strtr($parts[1], '-_', '+/')), true);
        if ($json === false) {
            return null;
        }

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    private function token(): string
    {
        $issuerId = trim((string) config('services.apple.issuer_id'));
        $keyId = trim((string) config('services.apple.key_id'));
        $bundleId = trim((string) config('services.apple.bundle_id'));
        $privateKey = $this->privateKey();

        if ($issuerId === '' || $keyId === '' || $bundleId === '' || $privateKey === '') {
            throw new AppleBillingException('App Store billing is not configured yet.');
        }

        $header = $this->base64Url((string) json_encode([
            'alg' => 'ES256',
            'kid' => $keyId,
            'typ' => 'JWT',
        ]));
        $now = time();
        $claims = $this->base64Url((string) json_encode([
            'iss' => $issuerId,
            'iat' => $now,
            'exp' => $now + 1200,
            'aud' => 'appstoreconnect-v1',
            'bid' => $bundleId,
        ]));
        $signingInput = $header.'.'.$claims;

        return $signingInput.'.'.$this->base64Url($this->sign($signingInput, $privateKey));
    }

    private function privateKey(): string
    {
        $configured = config('services.apple.private_key');
        if (! is_string($configured) || trim($configured) === '') {
            return '';
        }

        $configured = trim($configured);
        if (
            (str_starts_with($configured, '"') && str_ends_with($configured, '"'))
            || (str_starts_with($configured, "'") && str_ends_with($configured, "'"))
        ) {
            $configured = substr($configured, 1, -1);
        }

        return str_replace('\\n', "\n", $configured);
    }

    private function sign(string $data, string $privateKey): string
    {
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new AppleBillingException('App Store billing is not configured yet.');
        }

        $signed = openssl_sign($data, $der, $key, OPENSSL_ALGO_SHA256);
        if ($signed !== true) {
            throw new AppleBillingException('App Store billing is not configured yet.');
        }

        return $this->derToRaw($der);
    }

    private function derToRaw(string $der): string
    {
        $offset = 0;
        if (($der[$offset] ?? '') !== "\x30") {
            throw new AppleBillingException('App Store billing is not configured yet.');
        }

        $offset++;
        $this->readLength($der, $offset);
        $r = $this->readInteger($der, $offset);
        $s = $this->readInteger($der, $offset);

        return $this->padCoordinate($r).$this->padCoordinate($s);
    }

    private function readInteger(string $der, int &$offset): string
    {
        if (($der[$offset] ?? '') !== "\x02") {
            throw new AppleBillingException('App Store billing is not configured yet.');
        }

        $offset++;
        $length = $this->readLength($der, $offset);
        $value = substr($der, $offset, $length);
        $offset += $length;

        return $value;
    }

    private function readLength(string $der, int &$offset): int
    {
        $byte = ord($der[$offset] ?? "\x00");
        $offset++;

        if (($byte & 0x80) === 0) {
            return $byte;
        }

        $count = $byte & 0x7F;
        $length = 0;
        for ($index = 0; $index < $count; $index++) {
            $length = ($length << 8) | ord($der[$offset] ?? "\x00");
            $offset++;
        }

        return $length;
    }

    private function padCoordinate(string $value): string
    {
        $value = ltrim($value, "\x00");
        if (strlen($value) > 32) {
            $value = substr($value, -32);
        }

        return str_pad($value, 32, "\x00", STR_PAD_LEFT);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function padBase64(string $value): string
    {
        $remainder = strlen($value) % 4;

        return $remainder === 0 ? $value : $value.str_repeat('=', 4 - $remainder);
    }
}
