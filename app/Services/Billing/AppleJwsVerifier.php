<?php

namespace App\Services\Billing;

class AppleJwsVerifier
{
    /**
     * Decode and cryptographically verify an App Store ES256 JWS.
     *
     * @return array<string, mixed>|null
     */
    public function decode(string $jws): ?array
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            return null;
        }

        $headerJson = $this->base64UrlDecode($parts[0]);
        $payloadJson = $this->base64UrlDecode($parts[1]);
        $signature = $this->base64UrlDecode($parts[2]);
        if ($headerJson === null || $payloadJson === null || $signature === null) {
            return null;
        }

        $header = json_decode($headerJson, true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'ES256') {
            return null;
        }

        $x5c = $header['x5c'] ?? null;
        if (! is_array($x5c) || $x5c === []) {
            return null;
        }

        $certificates = [];
        foreach ($x5c as $encoded) {
            if (! is_string($encoded) || $encoded === '') {
                return null;
            }
            $pem = $this->certificatePem($encoded);
            if ($pem === null) {
                return null;
            }
            $certificates[] = $pem;
        }

        if (! $this->chainIsTrusted($certificates)) {
            return null;
        }

        $publicKey = openssl_pkey_get_public($certificates[0]);
        if ($publicKey === false) {
            return null;
        }

        $derSignature = $this->rawEcdsaSignatureToDer($signature);
        if ($derSignature === null) {
            return null;
        }

        $verified = openssl_verify(
            $parts[0].'.'.$parts[1],
            $derSignature,
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );
        if ($verified !== 1) {
            return null;
        }

        $payload = json_decode($payloadJson, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  list<string>  $certificates
     */
    private function chainIsTrusted(array $certificates): bool
    {
        $trustedRoot = $this->trustedRootPem();
        if ($trustedRoot === '') {
            return false;
        }

        $count = count($certificates);
        for ($index = 0; $index < $count - 1; $index++) {
            if (@openssl_x509_verify($certificates[$index], $certificates[$index + 1]) !== 1) {
                return false;
            }
        }

        $leafRoot = $certificates[$count - 1];
        $trustedFingerprint = @openssl_x509_fingerprint($trustedRoot, 'sha256');
        $chainFingerprint = @openssl_x509_fingerprint($leafRoot, 'sha256');
        if (is_string($trustedFingerprint) && $trustedFingerprint === $chainFingerprint) {
            return true;
        }

        return @openssl_x509_verify($leafRoot, $trustedRoot) === 1;
    }

    private function trustedRootPem(): string
    {
        $path = config('services.apple.jws_root_pem');
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            return '';
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? trim($contents) : '';
    }

    private function certificatePem(string $derBase64): ?string
    {
        $der = base64_decode($derBase64, true);
        if ($der === false || $der === '') {
            return null;
        }

        $pem = "-----BEGIN CERTIFICATE-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END CERTIFICATE-----\n";

        $parsed = @openssl_x509_read($pem);

        return $parsed === false ? null : $pem;
    }

    private function rawEcdsaSignatureToDer(string $raw): ?string
    {
        if (strlen($raw) !== 64) {
            return null;
        }

        $r = $this->encodeDerInteger(substr($raw, 0, 32));
        $s = $this->encodeDerInteger(substr($raw, 32, 32));
        $body = $r.$s;

        return "\x30".chr(strlen($body)).$body;
    }

    private function encodeDerInteger(string $coordinate): string
    {
        $coordinate = ltrim($coordinate, "\x00");
        if ($coordinate === '' || (ord($coordinate[0]) & 0x80) !== 0) {
            $coordinate = "\x00".$coordinate;
        }

        return "\x02".chr(strlen($coordinate)).$coordinate;
    }

    private function base64UrlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
