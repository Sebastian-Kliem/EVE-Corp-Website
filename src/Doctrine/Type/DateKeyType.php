<?php

namespace App\Doctrine\Type;

use App\Doctrine\DateKey;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateImmutableType;

/**
 * DATE column hydrated as DateKey, so it can be an identifier column.
 */
class DateKeyType extends DateImmutableType
{
    public const NAME = 'date_key';

    public function convertToPHPValue($value, AbstractPlatform $platform): ?DateKey
    {
        $date = parent::convertToPHPValue($value, $platform);

        return $date === null ? null : DateKey::fromDate($date);
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
