<?php

namespace App\Entity\Orders;

use App\Repository\BuyOrderRepository;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BuyOrderRepository::class)]
#[ORM\Table(name: 'buy_order')]
class BuyOrder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $item = null;

    #[ORM\Column]
    private ?int $amount = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'buyer_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $buyer = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'fulfiller_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $fulfiller = null;

    #[ORM\Column(nullable: true)]
    private ?bool $fullfilled = null;

    #[ORM\Column(nullable: true, options: ["default" => 100])]
    private ?int $percentToJitaBuy = 100;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): ?string
    {
        return $this->item;
    }

    public function setItem(string $item): static
    {
        $this->item = $item;

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

    public function getBuyer(): ?User
    {
        return $this->buyer;
    }

    public function setBuyer(User $buyer): static
    {
        $this->buyer = $buyer;

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

    public function isFullfilled(): ?bool
    {
        return $this->fullfilled;
    }

    public function setFullfilled(?bool $fullfilled): static
    {
        $this->fullfilled = $fullfilled;

        return $this;
    }

    public function getPercentToJitaBuy(): ?int
    {
        return $this->percentToJitaBuy;
    }

    public function setPercentToJitaBuy(?int $percentToJitaBuy): static
    {
        $this->percentToJitaBuy = $percentToJitaBuy;

        return $this;
    }

    private ?array $jitaPriceInfo = null;

    public function getJitaPriceInfo(): ?array
    {
        return $this->jitaPriceInfo;
    }

    public function setJitaPriceInfo(?array $jitaPriceInfo): void
    {
        $this->jitaPriceInfo = $jitaPriceInfo;
    }

    public function getCalculatedTotal(): ?float
    {
        if ($this->jitaPriceInfo === null || $this->jitaPriceInfo['price'] === null) {
            return null;
        }
        return $this->jitaPriceInfo['price'] * $this->amount * ($this->percentToJitaBuy / 100);
    }
}
