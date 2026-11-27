<?php

namespace App\Entity\Fitting;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One fitting slot of a fleet composition with role and wanted ship count.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fleet_composition_entry')]
class FleetCompositionEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FleetComposition::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FleetComposition $fleet = null;

    #[ORM\ManyToOne(targetEntity: Fitting::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Fitting $fitting;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $quantity = 1;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    public function __construct(Fitting $fitting)
    {
        $this->fitting = $fitting;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFleet(): ?FleetComposition
    {
        return $this->fleet;
    }

    public function setFleet(?FleetComposition $fleet): static
    {
        $this->fleet = $fleet;
        return $this;
    }

    public function getFitting(): Fitting
    {
        return $this->fitting;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): static
    {
        $this->role = $role !== null ? mb_substr($role, 0, 100) : null;
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = max(1, $quantity);
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
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
}
