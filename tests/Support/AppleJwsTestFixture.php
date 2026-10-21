<?php

namespace Tests\Support;

final class AppleJwsTestFixture
{
    private string $rootPem;

    private string $privateKeyPem;

    private string $certificateDerBase64;

    private function __construct(string $rootPem, string $privateKeyPem, string $certificateDerBase64)
    {
        $this->rootPem = $rootPem;
        $this->privateKeyPem = $privateKeyPem;
        $this->certificateDerBase64 = $certificateDerBase64;
    }

    public static function create(): self
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($key === false) {
            throw new \RuntimeException('Unable to create EC key for Apple JWS fixtures.');
        }

        $csr = openssl_csr_new([
            'commonName' => 'PLNR Test Apple Root',
            'organizationName' => 'PLNR Tests',
            'countryName' => 'US',
        ], $key, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            throw new \RuntimeException('Unable to create CSR for Apple JWS fixtures.');
        }

        $certificate = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256'], time());
        if ($certificate === false) {
            throw new \RuntimeException('Unable to sign certificate for Apple JWS fixtures.');
        }

        if (! openssl_x509_export($certificate, $rootPem) || ! is_string($rootPem)) {
            throw new \RuntimeException('Unable to export certificate PEM.');
        }

        if (! openssl_pkey_export($key, $privateKeyPem) || ! is_string($privateKeyPem)) {
            throw new \RuntimeException('Unable to export private key PEM.');
        }

        if (! preg_match('/-----BEGIN CERTIFICATE-----\s*(.+?)\s*-----END CERTIFICATE-----/s', $rootPem, $matches)) {
            throw new \RuntimeException('Unable to extract certificate DER for x5c.');
        }

        $certificateDerBase64 = preg_replace('/\s+/', '', $matches[1]);
        if (! is_string($certificateDerBase64) || $certificateDerBase64 === '') {
            throw new \RuntimeException('Unable to extract certificate DER for x5c.');
        }

        return new self($rootPem, $privateKeyPem, $certificateDerBase64);
    }

    public function rootPem(): string
    {
        return $this->rootPem;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function sign(array $claims): string
    {
        $header = $this->base64Url((string) json_encode([
            'alg' => 'ES256',
            'x5c' => [$this->certificateDerBase64],
        ]));
        $payload = $this->base64Url((string) json_encode($claims));
        $signingInput = $header.'.'.$payload;

        $key = openssl_pkey_get_private($this->privateKeyPem);
        if ($key === false) {
            throw new \RuntimeException('Unable to load fixture private key.');
        }

        $signed = openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256);
        if ($signed !== true || ! is_string($der)) {
            throw new \RuntimeException('Unable to sign fixture JWS.');
        }

        return $signingInput.'.'.$this->base64Url($this->derToRaw($der));
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function signWithTamperedSignature(array $claims): string
    {
        $jws = $this->sign($claims);
        $parts = explode('.', $jws);
        $parts[2] = $this->base64Url(str_repeat("\x01", 64));

        return implode('.', $parts);
    }

    private function derToRaw(string $der): string
    {
        $offset = 0;
        if (($der[$offset] ?? '') !== "\x30") {
            throw new \RuntimeException('Invalid DER signature.');
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
            throw new \RuntimeException('Invalid DER integer.');
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
}
