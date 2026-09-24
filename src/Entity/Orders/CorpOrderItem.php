<?php

namespace App\Entity\Orders;

use App\Entity\User;
use App\Repository\CorpOrderItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CorpOrderItemRepository::class)]
#[ORM\Table(name: 'corp_order_items')]
class CorpOrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CorpOrder::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CorpOrder $order = null;

    #[ORM\Column]
    private ?int $typeId = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column]
    private ?int $amount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2)]
    private ?string $unitPrice = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2)]
    private ?string $totalPrice = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private ?string $unitVolume = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private ?string $totalVolume = '0.00';

    #[ORM\Column(length: 50, options: ['default' => 'cargo'])]
    private string $slot = 'cargo';

    #[ORM\Column(options: ['default' => 100])]
    private int $sortOrder = 100;

    #[ORM\Column(options: ['default' => false])]
    private bool $isFulfilled = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'fulfiller_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $fulfiller = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fulfilledAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): ?CorpOrder
    {
        return $this->order;
    }

    public function setOrder(?CorpOrder $order): static
    {
        $this->order = $order;
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): static
    {
        $this->amount = $amount;
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

    public function getTotalPrice(): ?string
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(string $totalPrice): static
    {
        $this->totalPrice = $totalPrice;
        return $this;
    }

    public function getUnitVolume(): ?string
    {
        return $this->unitVolume;
    }

    public function setUnitVolume(string $unitVolume): static
    {
        $this->unitVolume = $unitVolume;
        return $this;
    }

    public function getTotalVolume(): ?string
    {
        return $this->totalVolume;
    }

    public function setTotalVolume(string $totalVolume): static
    {
        $this->totalVolume = $totalVolume;
        return $this;
    }

    public function getSlot(): string
    {
        return $this->slot;
    }

    public function setSlot(string $slot): static
    {
        $this->slot = $slot;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function isFulfilled(): bool
    {
        return $this->isFulfilled;
    }

    public function setIsFulfilled(bool $isFulfilled): static
    {
        $this->isFulfilled = $isFulfilled;
        if ($isFulfilled && $this->fulfilledAt === null) {
            $this->fulfilledAt = new \DateTimeImmutable();
        } elseif (!$isFulfilled) {
            $this->fulfilledAt = null;
        }
        return $this;
    }

    public function getFulfiller(): ?User
    {
        return $this->fulfiller;
    }

    public function setFulfiller(?User $fulfiller): static
    {
        $this->fulfiller = $fulfiller;
        return $this;
    }

    public function getFulfilledAt(): ?\DateTimeImmutable
    {
        return $this->fulfilledAt;
    }

    public function setFulfilledAt(?\DateTimeImmutable $fulfilledAt): static
    {
        $this->fulfilledAt = $fulfilledAt;
        return $this;
    }
}
