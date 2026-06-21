<?php

namespace App\Entity;

use App\Repository\EveCharacterWalletJournalEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EveCharacterWalletJournalEntryRepository::class)]
#[ORM\Table(name: 'eve_character_wallet_journal_entry')]
#[ORM\UniqueConstraint(name: 'uniq_char_wallet_ref', columns: ['character_id', 'ref_id'])]
#[ORM\Index(columns: ['character_id'])]
#[ORM\Index(columns: ['date'])]
class EveCharacterWalletJournalEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EveCharacter::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?EveCharacter $character = null;

    #[ORM\Column(type: Types::BIGINT)]
    private ?string $refId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 100)]
    private ?string $refType = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2)]
    private ?string $amount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2)]
    private ?string $balance = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    private ?int $firstPartyId = null;

    #[ORM\Column(nullable: true)]
    private ?int $secondPartyId = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $contextId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $contextIdType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $tax = null;

    #[ORM\Column(nullable: true)]
    private ?int $taxReceiverId = null;

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

    public function getRefId(): ?string
    {
        return $this->refId;
    }

    public function setRefId(string $refId): static
    {
        $this->refId = $refId;

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

    public function getRefType(): ?string
    {
        return $this->refType;
    }

    public function setRefType(string $refType): static
    {
        $this->refType = $refType;

        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getBalance(): ?string
    {
        return $this->balance;
    }

    public function setBalance(string $balance): static
    {
        $this->balance = $balance;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getFirstPartyId(): ?int
    {
        return $this->firstPartyId;
    }

    public function setFirstPartyId(?int $firstPartyId): static
    {
        $this->firstPartyId = $firstPartyId;

        return $this;
    }

    public function getSecondPartyId(): ?int
    {
        return $this->secondPartyId;
    }

    public function setSecondPartyId(?int $secondPartyId): static
    {
        $this->secondPartyId = $secondPartyId;

        return $this;
    }

    public function getContextId(): ?string
    {
        return $this->contextId;
    }

    public function setContextId(?string $contextId): static
    {
        $this->contextId = $contextId;

        return $this;
    }

    public function getContextIdType(): ?string
    {
        return $this->contextIdType;
    }

    public function setContextIdType(?string $contextIdType): static
    {
        $this->contextIdType = $contextIdType;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getTax(): ?string
    {
        return $this->tax;
    }

    public function setTax(?string $tax): static
    {
        $this->tax = $tax;

        return $this;
    }

    public function getTaxReceiverId(): ?int
    {
        return $this->taxReceiverId;
    }

    public function setTaxReceiverId(?int $taxReceiverId): static
    {
        $this->taxReceiverId = $taxReceiverId;

        return $this;
    }
}
