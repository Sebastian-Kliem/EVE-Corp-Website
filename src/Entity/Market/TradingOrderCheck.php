<?php

namespace App\Entity\Market;

use App\Repository\TradingOrderCheckRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Position of a trading character's order in the hub order book, written by every hub order book sync.
 */
#[ORM\Entity(repositoryClass: TradingOrderCheckRepository::class)]
#[ORM\Table(name: 'trading_order_check')]
#[ORM\Index(columns: ['character_id'])]
#[ORM\Index(columns: ['location_id'])]
class TradingOrderCheck
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint_int')]
    private ?int $orderId = null;

    #[ORM\Column(type: 'bigint_int')]
    private ?int $characterId = null;

    #[ORM\Column(type: 'bigint_int')]
    private ?int $locationId = null;

    #[ORM\Column]
    private ?int $typeId = null;

    #[ORM\Column]
    private bool $isBuy = false;

    // False when the order was no longer in the book (filled, cancelled or expired)
    #[ORM\Column]
    private bool $inBook = false;

    // Price from the public book, newer than the character order sync
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $currentPrice = null;

    #[ORM\Column(type: 'bigint_int', nullable: true)]
    private ?int $currentVolumeRemain = null;

    // Best price of other players, orders of the user's own characters do not count
    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $bestCompetitorPrice = null;

    #[ORM\Column]
    private int $competitorsAhead = 0;

    #[ORM\Column(type: 'bigint_int')]
    private int $competitorVolumeAhead = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $checkedAt = null;

    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    public function getCharacterId(): ?int
    {
        return $this->characterId;
    }

    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    public function getTypeId(): ?int
    {
        return $this->typeId;
    }

    public function isBuy(): bool
    {
        return $this->isBuy;
    }

    public function isInBook(): bool
    {
        return $this->inBook;
    }

    public function getCurrentPrice(): ?string
    {
        return $this->currentPrice;
    }

    public function getCurrentVolumeRemain(): ?int
    {
        return $this->currentVolumeRemain;
    }

    public function getBestCompetitorPrice(): ?string
    {
        return $this->bestCompetitorPrice;
    }

    public function getCompetitorsAhead(): int
    {
        return $this->competitorsAhead;
    }

    public function getCompetitorVolumeAhead(): int
    {
        return $this->competitorVolumeAhead;
    }

    public function getCheckedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }
}
