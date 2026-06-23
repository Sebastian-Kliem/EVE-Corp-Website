<?php

namespace App\Entity;

use App\Repository\EveCharacterMarketTransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EveCharacterMarketTransactionRepository::class)]
#[ORM\Table(name: 'eve_character_market_transaction')]
#[ORM\UniqueConstraint(name: 'uniq_char_market_trans_id', columns: ['character_id', 'transaction_id'])]
#[ORM\Index(columns: ['character_id'])]
#[ORM\Index(columns: ['date'])]
#[ORM\Index(columns: ['type_id'])]
class EveCharacterMarketTransaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EveCharacter::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?EveCharacter $character = null;

    #[ORM\Column(type: Types::BIGINT)]
    private ?string $transactionId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column]
    private ?int $typeId = null;

    #[ORM\Column(type: Types::BIGINT)]
    private ?string $quantity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2)]
    private ?string $unitPrice = null;

    #[ORM\Column]
    private ?bool $isBuy = null;

    #[ORM\Column]
    private ?int $clientId = null;

    #[ORM\Column(type: Types::BIGINT)]
    private ?string $locationId = null;

    #[ORM\Column(type: Types::BIGINT)]
    private ?string $journalRefId = null;

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

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function setTransactionId(string $transactionId): static
    {
        $this->transactionId = $transactionId;
        return $this;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;
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

    public function getQuantity(): ?string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): static
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getUnitPrice(): ?string
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(string $unitPrice): static
    {
        $this->unitPrice = $unitPrice;
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

    public function getClientId(): ?int
    {
        return $this->clientId;
    }

    public function setClientId(int $clientId): static
    {
        $this->clientId = $clientId;
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

    public function getJournalRefId(): ?string
    {
        return $this->journalRefId;
    }

    public function setJournalRefId(string $journalRefId): static
    {
        $this->journalRefId = $journalRefId;
        return $this;
    }

    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'transactionId' => $this->transactionId,
            'date' => $this->date,
            'typeId' => $this->typeId,
            'quantity' => $this->quantity,
            'unitPrice' => $this->unitPrice,
            'isBuy' => $this->isBuy,
            'clientId' => $this->clientId,
            'locationId' => $this->locationId,
            'journalRefId' => $this->journalRefId,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data['id'] ?? null;
        $this->transactionId = $data['transactionId'] ?? null;
        $this->date = $data['date'] ?? null;
        $this->typeId = $data['typeId'] ?? null;
        $this->quantity = $data['quantity'] ?? null;
        $this->unitPrice = $data['unitPrice'] ?? null;
        $this->isBuy = $data['isBuy'] ?? null;
        $this->clientId = $data['clientId'] ?? null;
        $this->locationId = $data['locationId'] ?? null;
        $this->journalRefId = $data['journalRefId'] ?? null;
    }
}
