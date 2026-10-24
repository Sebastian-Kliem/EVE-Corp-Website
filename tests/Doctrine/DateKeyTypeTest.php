<?php

namespace App\Tests\Doctrine;

use App\Doctrine\DateKey;
use App\Doctrine\Type\DateKeyType;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;

class DateKeyTypeTest extends TestCase
{
    public function testDatabaseValueIsHydratedAsDateKey(): void
    {
        $date = (new DateKeyType())->convertToPHPValue('2026-10-22', new MariaDBPlatform());

        $this->assertInstanceOf(DateKey::class, $date);
        $this->assertSame('2026-10-22', (string)$date);
    }

    public function testDateKeyCanBePartOfACompositeIdentifier(): void
    {
        $idHash = UnitOfWork::getIdHashByIdentifier([10000002, 34, DateKey::fromDate(new \DateTimeImmutable('2026-10-22 13:45:00'))]);

        $this->assertSame('10000002 34 2026-10-22', $idHash);
    }
}
