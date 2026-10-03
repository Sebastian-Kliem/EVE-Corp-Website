<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts secrets at rest (ESI tokens) with libsodium secretbox and the ESI_TOKEN_KEY.
 */
class TokenCipher
{
    private const PREFIX = 'enc:v1:';

    public function __construct(
        #[Autowire('%env(ESI_TOKEN_KEY)%')]
        #[\SensitiveParameter]
        private readonly string $encodedKey
    ) {}

    public function encrypt(?string $plainText): ?string
    {
        if ($plainText === null || $this->isEncrypted($plainText)) {
            return $plainText;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipherText = sodium_crypto_secretbox($plainText, $nonce, $this->_getKey());

        return self::PREFIX . base64_encode($nonce . $cipherText);
    }

    public function decrypt(?string $storedValue): ?string
    {
        // Values written before encryption was introduced are returned unchanged
        if ($storedValue === null || !$this->isEncrypted($storedValue)) {
            return $storedValue;
        }

        $decoded = base64_decode(substr($storedValue, strlen(self::PREFIX)), true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Encrypted token is malformed.');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plainText = sodium_crypto_secretbox_open(substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->_getKey());
        if ($plainText === false) {
            throw new \RuntimeException('Encrypted token could not be decrypted, ESI_TOKEN_KEY does not match.');
        }

        return $plainText;
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    public function isConfigured(): bool
    {
        $key = base64_decode($this->encodedKey, true);

        return $key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
    }

    private function _getKey(): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('ESI_TOKEN_KEY is missing or invalid (expected base64 of 32 random bytes).');
        }

        return base64_decode($this->encodedKey, true);
    }
}
