<?php

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\BigIntType;

/**
 * BIGINT column hydrated as int for int-typed properties.
 *
 * DBAL 3 returns BIGINT as string; on an int property that differs from the
 * original data, so every flush sent a no-op UPDATE for each loaded entity.
 */
class IntegerBigIntType extends BigIntType
{
    public const NAME = 'bigint_int';

    public function convertToPHPValue($value, AbstractPlatform $platform): ?int
    {
        return $value === null ? null : (int) $value;
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
