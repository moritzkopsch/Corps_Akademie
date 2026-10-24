<?php
declare(strict_types=1);
namespace Corpsakademie\Infrastructure;

final class Crypto
{
    public function __construct(private readonly Config $config) {}

    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function subject(string $scope, string $value): string
    {
        return hash_hmac('sha256', $scope . "\0" . $value, $this->config->key);
    }

    public function encrypt(string $value, string $purpose): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($value, $purpose, $nonce, $this->config->key));
    }

    public function decrypt(string $value, string $purpose, string $keyId): string
    {
        $data = base64_decode($value, true);
        $length = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($keyId !== $this->config->keyId || $data === false || strlen($data) <= $length) {
            throw new \RuntimeException('Verschlüsselte Daten konnten nicht gelesen werden.');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($data, $length), $purpose, substr($data, 0, $length), $this->config->key);
        if ($plain === false) {
            throw new \RuntimeException('Verschlüsselte Daten konnten nicht geprüft werden.');
        }
        return $plain;
    }
}
