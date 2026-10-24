<?php

namespace App\Entity\Market;

use App\Doctrine\DateKey;
use App\Repository\MarketHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Daily market history of one type in one region (source: EVE Ref dumps, fallback ESI).
 */
#[ORM\Entity(repositoryClass: MarketHistoryRepository::class)]
#[ORM\Table(name: 'market_history')]
#[ORM\Index(columns: ['type_id', 'date'])]
class MarketHistory
{
    // Days kept locally, older days can be re-imported from EVE Ref on demand
    public const RETENTION_DAYS_HUB_REGION = 400;
    public const RETENTION_DAYS_OTHER_REGION = 90;

    #[ORM\Id]
    #[ORM\Column]
    private ?int $regionId = null;

    #[ORM\Id]
    #[ORM\Column]
    private ?int $typeId = null;

    #[ORM\Id]
    #[ORM\Column(type: 'date_key')]
    private ?DateKey $date = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2)]
    private ?string $average = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2)]
    private ?string $highest = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2)]
    private ?string $lowest = null;

    #[ORM\Column(type: 'bigint_int')]
    private ?int $volume = null;

    #[ORM\Column(type: 'bigint_int')]
    private ?int $orderCount = null;

    public function getRegionId(): ?int
    {
        return $this->regionId;
    }

    public function setRegionId(int $regionId): static
    {
        $this->regionId = $regionId;
        return $this;
    }

    public function getTypeId(): ?int
    {
        return $this->typeId;
    }

    public function setTypeId(int $typeId): static
    {
        $this->typeId = $typeId;
        return $this;
    }

    public function getDate(): ?DateKey
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): static
    {
        $this->date = DateKey::fromDate($date);
        return $this;
    }

    public function getAverage(): ?string
    {
        return $this->average;
    }

    public function setAverage(string $average): static
    {
        $this->average = $average;
        return $this;
    }

    public function getHighest(): ?string
    {
        return $this->highest;
    }

    public function setHighest(string $highest): static
    {
        $this->highest = $highest;
        return $this;
    }

    public function getLowest(): ?string
    {
        return $this->lowest;
    }

    public function setLowest(string $lowest): static
    {
        $this->lowest = $lowest;
        return $this;
    }

    public function getVolume(): ?int
    {
        return $this->volume;
    }

    public function setVolume(int $volume): static
    {
        $this->volume = $volume;
        return $this;
    }

    public function getOrderCount(): ?int
    {
        return $this->orderCount;
    }

    public function setOrderCount(int $orderCount): static
    {
        $this->orderCount = $orderCount;
        return $this;
    }
}
