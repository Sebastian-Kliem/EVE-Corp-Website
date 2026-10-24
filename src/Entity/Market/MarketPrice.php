<?php

namespace App\Entity\Market;

use App\Repository\MarketPriceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Current order book key figures of one type at a hub station or in a whole region, replaced on every order book pull.
 */
#[ORM\Entity(repositoryClass: MarketPriceRepository::class)]
#[ORM\Table(name: 'market_price')]
#[ORM\UniqueConstraint(name: 'uniq_market_price_location_type', columns: ['scope', 'location_id', 'type_id'])]
#[ORM\Index(columns: ['region_id', 'type_id'])]
#[ORM\Index(columns: ['type_id'])]
class MarketPrice
{
    public const SCOPE_STATION = 'station';
    public const SCOPE_REGION = 'region';

    // Orders within this distance to the best price count as direct competition
    public const DEPTH_PRICE_RANGE = 0.05;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private ?string $scope = null;

    #[ORM\Column(type: 'bigint_int')]
    private ?int $locationId = null;

    #[ORM\Column]
    private ?int $regionId = null;

    #[ORM\Column]
    private ?int $typeId = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $buyBest = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $buyPercentile = null;

    #[ORM\Column(type: 'bigint_int', options: ['default' => 0])]
    private int $buyVolume = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $buyOrderCount = 0;

    #[ORM\Column(type: 'bigint_int', options: ['default' => 0])]
    private int $buyDepthVolume = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $buyDepthOrderCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $buyUpdated1h = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $buyUpdated24h = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $buyBestIssuedAt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $sellBest = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $sellPercentile = null;

    #[ORM\Column(type: 'bigint_int', options: ['default' => 0])]
    private int $sellVolume = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $sellOrderCount = 0;

    #[ORM\Column(type: 'bigint_int', options: ['default' => 0])]
    private int $sellDepthVolume = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $sellDepthOrderCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $sellUpdated1h = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $sellUpdated24h = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sellBestIssuedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $fetchedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(string $scope): static
    {
        $this->scope = $scope;
        return $this;
    }

    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    public function setLocationId(int $locationId): static
    {
        $this->locationId = $locationId;
        return $this;
    }

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

    public function getBuyBest(): ?string
    {
        return $this->buyBest;
    }

    public function setBuyBest(?string $buyBest): static
    {
        $this->buyBest = $buyBest;
        return $this;
    }

    public function getBuyPercentile(): ?string
    {
        return $this->buyPercentile;
    }

    public function setBuyPercentile(?string $buyPercentile): static
    {
        $this->buyPercentile = $buyPercentile;
        return $this;
    }

    public function getBuyVolume(): int
    {
        return $this->buyVolume;
    }

    public function setBuyVolume(int $buyVolume): static
    {
        $this->buyVolume = $buyVolume;
        return $this;
    }

    public function getBuyOrderCount(): int
    {
        return $this->buyOrderCount;
    }

    public function setBuyOrderCount(int $buyOrderCount): static
    {
        $this->buyOrderCount = $buyOrderCount;
        return $this;
    }

    public function getBuyDepthVolume(): int
    {
        return $this->buyDepthVolume;
    }

    public function setBuyDepthVolume(int $buyDepthVolume): static
    {
        $this->buyDepthVolume = $buyDepthVolume;
        return $this;
    }

    public function getBuyDepthOrderCount(): int
    {
        return $this->buyDepthOrderCount;
    }

    public function setBuyDepthOrderCount(int $buyDepthOrderCount): static
    {
        $this->buyDepthOrderCount = $buyDepthOrderCount;
        return $this;
    }

    public function getBuyUpdated1h(): int
    {
        return $this->buyUpdated1h;
    }

    public function setBuyUpdated1h(int $buyUpdated1h): static
    {
        $this->buyUpdated1h = $buyUpdated1h;
        return $this;
    }

    public function getBuyUpdated24h(): int
    {
        return $this->buyUpdated24h;
    }

    public function setBuyUpdated24h(int $buyUpdated24h): static
    {
        $this->buyUpdated24h = $buyUpdated24h;
        return $this;
    }

    public function getBuyBestIssuedAt(): ?\DateTimeImmutable
    {
        return $this->buyBestIssuedAt;
    }

    public function setBuyBestIssuedAt(?\DateTimeImmutable $buyBestIssuedAt): static
    {
        $this->buyBestIssuedAt = $buyBestIssuedAt;
        return $this;
    }

    public function getSellBest(): ?string
    {
        return $this->sellBest;
    }

    public function setSellBest(?string $sellBest): static
    {
        $this->sellBest = $sellBest;
        return $this;
    }

    public function getSellPercentile(): ?string
    {
        return $this->sellPercentile;
    }

    public function setSellPercentile(?string $sellPercentile): static
    {
        $this->sellPercentile = $sellPercentile;
        return $this;
    }

    public function getSellVolume(): int
    {
        return $this->sellVolume;
    }

    public function setSellVolume(int $sellVolume): static
    {
        $this->sellVolume = $sellVolume;
        return $this;
    }

    public function getSellOrderCount(): int
    {
        return $this->sellOrderCount;
    }

    public function setSellOrderCount(int $sellOrderCount): static
    {
        $this->sellOrderCount = $sellOrderCount;
        return $this;
    }

    public function getSellDepthVolume(): int
    {
        return $this->sellDepthVolume;
    }

    public function setSellDepthVolume(int $sellDepthVolume): static
    {
        $this->sellDepthVolume = $sellDepthVolume;
        return $this;
    }

    public function getSellDepthOrderCount(): int
    {
        return $this->sellDepthOrderCount;
    }

    public function setSellDepthOrderCount(int $sellDepthOrderCount): static
    {
        $this->sellDepthOrderCount = $sellDepthOrderCount;
        return $this;
    }

    public function getSellUpdated1h(): int
    {
        return $this->sellUpdated1h;
    }

    public function setSellUpdated1h(int $sellUpdated1h): static
    {
        $this->sellUpdated1h = $sellUpdated1h;
        return $this;
    }

    public function getSellUpdated24h(): int
    {
        return $this->sellUpdated24h;
    }

    public function setSellUpdated24h(int $sellUpdated24h): static
    {
        $this->sellUpdated24h = $sellUpdated24h;
        return $this;
    }

    public function getSellBestIssuedAt(): ?\DateTimeImmutable
    {
        return $this->sellBestIssuedAt;
    }

    public function setSellBestIssuedAt(?\DateTimeImmutable $sellBestIssuedAt): static
    {
        $this->sellBestIssuedAt = $sellBestIssuedAt;
        return $this;
    }

    public function getFetchedAt(): ?\DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function setFetchedAt(\DateTimeImmutable $fetchedAt): static
    {
        $this->fetchedAt = $fetchedAt;
        return $this;
    }
}
