<?php

namespace App\Entity;

use App\Repository\EveCharacterMarketOrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EveCharacterMarketOrderRepository::class)]
#[ORM\Table(name: 'eve_character_market_order')]
#[ORM\UniqueConstraint(name: 'uniq_char_market_order_id', columns: ['character_id', 'order_id'])]
#[ORM\Index(columns: ['character_id'])]
#[ORM\Index(columns: ['type_id'])]
class EveCharacterMarketOrder
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

    #[ORM\Column(type: Types::BIGINT)]
    private ?string $locationId = null;

    #[ORM\Column]
    private ?int $volumeTotal = null;

    #[ORM\Column]
    private ?int $volumeRemain = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2)]
    private ?string $price = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $escrow = null;

    #[ORM\Column]
    private ?bool $isBuy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $issued = null;

    #[ORM\Column]
    private ?int $duration = null;

    #[ORM\Column(name: '`range`', length: 50)]
    private ?string $range = null;

    #[ORM\Column(nullable: true)]
    private ?int $minVolume = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacter(): ?EveCharacter
    {
        return $this->character;
    }

    public function setCharacter(?EveCharacter $character): static
    {
        $this->character = $character;
        return $this;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function setOrderId(string $orderId): static
    {
        $this->orderId = $orderId;
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

    public function getLocationId(): ?string
    {
        return $this->locationId;
    }

    public function setLocationId(string $locationId): static
    {
        $this->locationId = $locationId;
        return $this;
    }

    public function getVolumeTotal(): ?int
    {
        return $this->volumeTotal;
    }

    public function setVolumeTotal(int $volumeTotal): static
    {
        $this->volumeTotal = $volumeTotal;
        return $this;
    }

    public function getVolumeRemain(): ?int
    {
        return $this->volumeRemain;
    }

    public function setVolumeRemain(int $volumeRemain): static
    {
        $this->volumeRemain = $volumeRemain;
        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getEscrow(): ?string
    {
        return $this->escrow;
    }

    public function setEscrow(?string $escrow): static
    {
        $this->escrow = $escrow;
        return $this;
    }

    public function isBuy(): ?bool
    {
        return $this->isBuy;
    }

    public function setIsBuy(bool $isBuy): static
    {
        $this->isBuy = $isBuy;
        return $this;
    }

    public function getIssued(): ?\DateTimeImmutable
    {
        return $this->issued;
    }

    public function setIssued(\DateTimeImmutable $issued): static
    {
        $this->issued = $issued;
        return $this;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function setDuration(int $duration): static
    {
        $this->duration = $duration;
        return $this;
    }

    public function getRange(): ?string
    {
        return $this->range;
    }

    public function setRange(string $range): static
    {
        $this->range = $range;
        return $this;
    }

    public function getMinVolume(): ?int
    {
        return $this->minVolume;
    }

    public function setMinVolume(?int $minVolume): static
    {
        $this->minVolume = $minVolume;
        return $this;
    }

    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'orderId' => $this->orderId,
            'typeId' => $this->typeId,
            'locationId' => $this->locationId,
            'volumeTotal' => $this->volumeTotal,
            'volumeRemain' => $this->volumeRemain,
            'price' => $this->price,
            'escrow' => $this->escrow,
            'isBuy' => $this->isBuy,
            'issued' => $this->issued,
            'duration' => $this->duration,
            'range' => $this->range,
            'minVolume' => $this->minVolume,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data['id'] ?? null;
        $this->orderId = $data['orderId'] ?? null;
        $this->typeId = $data['typeId'] ?? null;
        $this->locationId = $data['locationId'] ?? null;
        $this->volumeTotal = $data['volumeTotal'] ?? null;
        $this->volumeRemain = $data['volumeRemain'] ?? null;
        $this->price = $data['price'] ?? null;
        $this->escrow = $data['escrow'] ?? null;
        $this->isBuy = $data['isBuy'] ?? null;
        $this->issued = $data['issued'] ?? null;
        $this->duration = $data['duration'] ?? null;
        $this->range = $data['range'] ?? null;
        $this->minVolume = $data['minVolume'] ?? null;
    }
}
