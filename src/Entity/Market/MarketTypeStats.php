<?php

namespace App\Entity\Market;

use App\Repository\MarketTypeStatsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Key figures of the market history per region and type, rebuilt after every history import so trading queries never scan market_history.
 */
#[ORM\Entity(repositoryClass: MarketTypeStatsRepository::class)]
#[ORM\Table(name: 'market_type_stats')]
class MarketTypeStats
{
    public const SHORT_WINDOW_DAYS = 7;
    public const LONG_WINDOW_DAYS = 30;

    #[ORM\Id]
    #[ORM\Column]
    private ?int $regionId = null;

    #[ORM\Id]
    #[ORM\Column]
    private ?int $typeId = null;

    // Average units per day, days without trades count as zero
    #[ORM\Column(type: Types::FLOAT)]
    private float $avgVolume7d = 0.0;

    #[ORM\Column(type: Types::FLOAT)]
    private float $avgVolume30d = 0.0;

    // Volume weighted average price
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $avgPrice7d = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $avgPrice30d = null;

    // Average of the daily lowest and highest trade price on traded days
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $avgLowest7d = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $avgHighest7d = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $tradedDays30d = 0;

    // Standard deviation of the daily average price relative to its mean
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $priceVolatility30d = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $computedAt = null;

    public function getRegionId(): ?int
    {
        return $this->regionId;
    }

    public function getTypeId(): ?int
    {
        return $this->typeId;
    }

    public function getAvgVolume7d(): float
    {
        return $this->avgVolume7d;
    }

    public function getAvgVolume30d(): float
    {
        return $this->avgVolume30d;
    }

    public function getAvgPrice7d(): ?string
    {
        return $this->avgPrice7d;
    }

    public function getAvgPrice30d(): ?string
    {
        return $this->avgPrice30d;
    }

    public function getAvgLowest7d(): ?string
    {
        return $this->avgLowest7d;
    }

    public function getAvgHighest7d(): ?string
    {
        return $this->avgHighest7d;
    }

    public function getTradedDays30d(): int
    {
        return $this->tradedDays30d;
    }

    public function getPriceVolatility30d(): ?float
    {
        return $this->priceVolatility30d;
    }

    public function getComputedAt(): ?\DateTimeImmutable
    {
        return $this->computedAt;
    }
}
