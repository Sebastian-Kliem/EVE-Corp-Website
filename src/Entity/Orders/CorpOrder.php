<?php

namespace App\Entity\Orders;

use App\Entity\User;
use App\Repository\CorpOrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CorpOrderRepository::class)]
#[ORM\Table(name: 'corp_orders')]
#[ORM\Index(columns: ['type'])]
#[ORM\Index(columns: ['status'])]
#[ORM\Index(columns: ['created_at'])]
class CorpOrder
{
    public const TYPE_BUY = 'BUY';
    public const TYPE_SELL = 'SELL';

    public const STATUS_OPEN = 'OPEN';
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_FULFILLED = 'FULFILLED';
    public const STATUS_CANCELLED = 'CANCELLED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $type = self::TYPE_BUY;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_OPEN])]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isFitting = false;

    #[ORM\Column(nullable: true)]
    private ?int $shipTypeId = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(options: ['default' => 100])]
    private int $percentToJita = 100;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2)]
    private string $totalPrice = '0.00';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $totalVolume = '0.00';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $contractId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fulfilledAt = null;

    /**
     * @var Collection<int, CorpOrderItem>
     */
    #[ORM\OneToMany(targetEntity: CorpOrderItem::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'name' => 'ASC'])]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = strtoupper($type);
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = strtoupper($status);
        if ($this->status === self::STATUS_FULFILLED && $this->fulfilledAt === null) {
            $this->fulfilledAt = new \DateTimeImmutable();
        } elseif ($this->status !== self::STATUS_FULFILLED) {
            $this->fulfilledAt = null;
        }
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function isFitting(): bool
    {
        return $this->isFitting;
    }

    public function setIsFitting(bool $isFitting): static
    {
        $this->isFitting = $isFitting;
        return $this;
    }

    public function getShipTypeId(): ?int
    {
        return $this->shipTypeId;
    }

    public function setShipTypeId(?int $shipTypeId): static
    {
        $this->shipTypeId = $shipTypeId;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getPercentToJita(): int
    {
        return $this->percentToJita;
    }

    public function setPercentToJita(int $percentToJita): static
    {
        $this->percentToJita = $percentToJita;
        return $this;
    }

    public function getTotalPrice(): string
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(string $totalPrice): static
    {
        $this->totalPrice = $totalPrice;
        return $this;
    }

    public function getTotalVolume(): string
    {
        return $this->totalVolume;
    }

    public function setTotalVolume(string $totalVolume): static
    {
        $this->totalVolume = $totalVolume;
        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;
        return $this;
    }

    public function getContractId(): ?string
    {
        return $this->contractId;
    }

    public function setContractId(?string $contractId): static
    {
        $this->contractId = $contractId;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
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

    /**
     * @return Collection<int, CorpOrderItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(CorpOrderItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setOrder($this);
        }

        return $this;
    }

    public function removeItem(CorpOrderItem $item): static
    {
        if ($this->items->removeElement($item)) {
            // set the owning side to null (unless already changed)
            if ($item->getOrder() === $this) {
                $item->setOrder(null);
            }
        }

        return $this;
    }

    /**
     * Recalculates total price, volume and dynamic status based on items.
     */
    public function recalculateTotals(): void
    {
        $totalPrice = 0.0;
        $totalVolume = 0.0;
        $fulfilledCount = 0;
        $totalCount = count($this->items);

        foreach ($this->items as $item) {
            $totalPrice += (float)$item->getTotalPrice();
            $totalVolume += (float)$item->getTotalVolume();
            if ($item->isFulfilled()) {
                $fulfilledCount++;
            }
        }

        $this->totalPrice = number_format($totalPrice, 2, '.', '');
        $this->totalVolume = number_format($totalVolume, 2, '.', '');

        if ($this->status !== self::STATUS_CANCELLED) {
            if ($totalCount > 0 && $fulfilledCount === $totalCount) {
                $this->setStatus(self::STATUS_FULFILLED);
            } elseif ($fulfilledCount > 0) {
                $this->setStatus(self::STATUS_IN_PROGRESS);
            } else {
                $this->setStatus(self::STATUS_OPEN);
            }
        }

        $this->updatedAt = new \DateTimeImmutable();
    }
}
