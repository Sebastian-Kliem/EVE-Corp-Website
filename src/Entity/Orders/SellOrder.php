<?php

namespace App\Entity\Orders;

use App\Repository\SellOrderRepository;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SellOrderRepository::class)]
#[ORM\Table(name: 'sell_order')]
class SellOrder
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
    #[ORM\JoinColumn(name: 'seller_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $seller = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'buyer_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $buyer = null;

    #[ORM\Column(nullable: true)]
    private ?bool $fullfilled = null;

    #[ORM\Column(nullable: true, options: ["default" => 100])]
    private ?int $percentToJitaSell = 100;

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

    public function getSeller(): ?User
    {
        return $this->seller;
    }

    public function setSeller(User $seller): static
    {
        $this->seller = $seller;

        return $this;
    }

    public function getBuyer(): ?User
    {
        return $this->buyer;
    }

    public function setBuyer(?User $buyer): static
    {
        $this->buyer = $buyer;

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

    public function getPercentToJitaSell(): ?int
    {
        return $this->percentToJitaSell;
    }

    public function setPercentToJitaSell(?int $percentToJitaSell): static
    {
        $this->percentToJitaSell = $percentToJitaSell;

        return $this;
    }
}
