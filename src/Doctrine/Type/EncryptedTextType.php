<?php

namespace App\Doctrine\Type;

use App\Security\TokenCipher;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\TextType;

/**
 * TEXT column that is transparently encrypted at rest via TokenCipher.
 */
class EncryptedTextType extends TextType
{
    public const NAME = 'encrypted_text';

    // DBAL instantiates types itself, so the cipher is injected once at kernel boot
    private static ?TokenCipher $cipher = null;

    public static function setCipher(TokenCipher $cipher): void
    {
        self::$cipher = $cipher;
    }

    public static function getCipher(): TokenCipher
    {
        if (self::$cipher === null) {
            throw new \LogicException('EncryptedTextType used before the TokenCipher was set.');
        }

        return self::$cipher;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        return self::getCipher()->encrypt($value === null ? null : (string) $value);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?string
    {
        return self::getCipher()->decrypt(parent::convertToPHPValue($value, $platform));
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
