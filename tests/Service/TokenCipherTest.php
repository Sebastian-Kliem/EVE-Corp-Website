<?php

namespace App\Tests\Service;

use App\Security\TokenCipher;
use PHPUnit\Framework\TestCase;

class TokenCipherTest extends TestCase
{
    public function testRoundTripUsesFreshNonce(): void
    {
        $cipher = new TokenCipher(base64_encode(random_bytes(32)));

        $first = $cipher->encrypt('refresh-token');
        $second = $cipher->encrypt('refresh-token');

        $this->assertNotSame($first, $second);
        $this->assertStringNotContainsString('refresh-token', $first);
        $this->assertSame('refresh-token', $cipher->decrypt($first));
        $this->assertSame($first, $cipher->encrypt($first));
    }

    public function testLegacyPlainTextAndNullPassThrough(): void
    {
        $cipher = new TokenCipher(base64_encode(random_bytes(32)));

        $this->assertSame('plain-token', $cipher->decrypt('plain-token'));
        $this->assertNull($cipher->encrypt(null));
        $this->assertNull($cipher->decrypt(null));
    }

    public function testWrongKeyFails(): void
    {
        $encrypted = (new TokenCipher(base64_encode(random_bytes(32))))->encrypt('refresh-token');

        $this->expectException(\RuntimeException::class);
        (new TokenCipher(base64_encode(random_bytes(32))))->decrypt($encrypted);
    }

    public function testMissingKeyFails(): void
    {
        $cipher = new TokenCipher('');

        $this->assertFalse($cipher->isConfigured());
        $this->expectException(\RuntimeException::class);
        $cipher->encrypt('refresh-token');
    }
}
