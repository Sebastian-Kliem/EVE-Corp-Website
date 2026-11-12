<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Every observed state of a personal market order; `issued` changes with each price modification and matches the broker fee booking.
 *
 * Written with plain inserts by MarketOrderSyncService, read by TradingBrokerFeeAttributor.
 */
#[ORM\Entity]
#[ORM\Table(name: 'eve_character_market_order_log')]
#[ORM\UniqueConstraint(name: 'uniq_market_order_log_state', columns: ['character_id', 'order_id', 'issued'])]
#[ORM\Index(columns: ['character_id', 'issued'])]
class EveCharacterMarketOrderLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EveCharacter::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?EveCharacter $character = null;

    #[ORM\Column(type: Types::BIGINT)]
    private ?string $orderId = null;

    #[ORM\Column]
    private ?int $typeId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $issued = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2)]
    private ?string $price = null;

    #[ORM\Column]
    private ?int $volumeTotal = null;

    #[ORM\Column]
    private ?int $volumeRemain = null;

    #[ORM\Column]
    private ?bool $isBuy = null;

    #[ORM\Column]
    private ?int $duration = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacter(): ?EveCharacter
    {
        return $this->character;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function getTypeId(): ?int
    {
        return $this->typeId;
    }

    public function getIssued(): ?\DateTimeImmutable
    {
        return $this->issued;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function getVolumeTotal(): ?int
    {
        return $this->volumeTotal;
    }

    public function getVolumeRemain(): ?int
    {
        return $this->volumeRemain;
    }

    public function isBuy(): ?bool
    {
        return $this->isBuy;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }
}
