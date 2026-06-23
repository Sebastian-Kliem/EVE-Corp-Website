<?php

namespace App\Entity;

use App\Repository\EveCharacterContractRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EveCharacterContractRepository::class)]
#[ORM\Table(name: 'eve_character_contract')]
#[ORM\UniqueConstraint(name: 'uniq_char_contract_id', columns: ['character_id', 'contract_id'])]
#[ORM\Index(columns: ['character_id'])]
#[ORM\Index(columns: ['date_expired'])]
#[ORM\Index(columns: ['date_completed'])]
class EveCharacterContract
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EveCharacter::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?EveCharacter $character = null;

    #[ORM\Column(type: Types::BIGINT)]
    private ?string $contractId = null;

    #[ORM\Column(length: 50)]
    private ?string $type = null; // item_exchange, auction, courier

    #[ORM\Column(length: 50)]
    private ?string $status = null; // outstanding, in_progress, finished_issuer, finished_contractor, finished, cancelled, rejected, failed

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $startLocationId = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $endLocationId = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $reward = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $collateral = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $buyout = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateIssued = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateExpired = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateCompleted = null;

    #[ORM\Column(type: Types::JSON)]
    private array $items = []; // List of items included in the contract: [['typeId' => 123, 'quantity' => 10, 'isIncluded' => true], ...]

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $title = null; // Contract description/comment

    #[ORM\Column(nullable: true)]
    private ?int $issuerId = null;

    #[ORM\Column(nullable: true)]
    private ?int $acceptorId = null;

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

    public function getContractId(): ?string
    {
        return $this->contractId;
    }

    public function setContractId(string $contractId): static
    {
        $this->contractId = $contractId;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getStartLocationId(): ?string
    {
        return $this->startLocationId;
    }

    public function setStartLocationId(?string $startLocationId): static
    {
        $this->startLocationId = $startLocationId;
        return $this;
    }

    public function getEndLocationId(): ?string
    {
        return $this->endLocationId;
    }

    public function setEndLocationId(?string $endLocationId): static
    {
        $this->endLocationId = $endLocationId;
        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getReward(): ?string
    {
        return $this->reward;
    }

    public function setReward(?string $reward): static
    {
        $this->reward = $reward;
        return $this;
    }

    public function getCollateral(): ?string
    {
        return $this->collateral;
    }

    public function setCollateral(?string $collateral): static
    {
        $this->collateral = $collateral;
        return $this;
    }

    public function getBuyout(): ?string
    {
        return $this->buyout;
    }

    public function setBuyout(?string $buyout): static
    {
        $this->buyout = $buyout;
        return $this;
    }

    public function getDateIssued(): ?\DateTimeImmutable
    {
        return $this->dateIssued;
    }

    public function setDateIssued(\DateTimeImmutable $dateIssued): static
    {
        $this->dateIssued = $dateIssued;
        return $this;
    }

    public function getDateExpired(): ?\DateTimeImmutable
    {
        return $this->dateExpired;
    }

    public function setDateExpired(\DateTimeImmutable $dateExpired): static
    {
        $this->dateExpired = $dateExpired;
        return $this;
    }

    public function getDateCompleted(): ?\DateTimeImmutable
    {
        return $this->dateCompleted;
    }

    public function setDateCompleted(?\DateTimeImmutable $dateCompleted): static
    {
        $this->dateCompleted = $dateCompleted;
        return $this;
    }

    public function getItems(): array
    {
        return $this->items ?? [];
    }

    public function setItems(array $items): static
    {
        $this->items = $items;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getIssuerId(): ?int
    {
        return $this->issuerId;
    }

    public function setIssuerId(?int $issuerId): static
    {
        $this->issuerId = $issuerId;
        return $this;
    }

    public function getAcceptorId(): ?int
    {
        return $this->acceptorId;
    }

    public function setAcceptorId(?int $acceptorId): static
    {
        $this->acceptorId = $acceptorId;
        return $this;
    }

    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'contractId' => $this->contractId,
            'type' => $this->type,
            'status' => $this->status,
            'startLocationId' => $this->startLocationId,
            'endLocationId' => $this->endLocationId,
            'price' => $this->price,
            'reward' => $this->reward,
            'collateral' => $this->collateral,
            'buyout' => $this->buyout,
            'dateIssued' => $this->dateIssued,
            'dateExpired' => $this->dateExpired,
            'dateCompleted' => $this->dateCompleted,
            'items' => $this->items,
            'title' => $this->title,
            'issuerId' => $this->issuerId,
            'acceptorId' => $this->acceptorId,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data['id'] ?? null;
        $this->contractId = $data['contractId'] ?? null;
        $this->type = $data['type'] ?? null;
        $this->status = $data['status'] ?? null;
        $this->startLocationId = $data['startLocationId'] ?? null;
        $this->endLocationId = $data['endLocationId'] ?? null;
        $this->price = $data['price'] ?? null;
        $this->reward = $data['reward'] ?? null;
        $this->collateral = $data['collateral'] ?? null;
        $this->buyout = $data['buyout'] ?? null;
        $this->dateIssued = $data['dateIssued'] ?? null;
        $this->dateExpired = $data['dateExpired'] ?? null;
        $this->dateCompleted = $data['dateCompleted'] ?? null;
        $this->items = $data['items'] ?? [];
        $this->title = $data['title'] ?? null;
        $this->issuerId = $data['issuerId'] ?? null;
        $this->acceptorId = $data['acceptorId'] ?? null;
    }
}
