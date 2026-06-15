<?php

namespace App\Entity;

use App\Repository\EveCorporationAssetRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EveCorporationAssetRepository::class)]
#[ORM\Index(columns: ['corporation_id'])]
#[ORM\Index(columns: ['type_id'])]
class EveCorporationAsset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint')]
    private ?int $corporationId = null;

    #[ORM\Column(type: 'bigint')]
    private ?int $itemId = null;

    #[ORM\Column]
    private ?int $typeId = null;

    #[ORM\Column(type: 'bigint')]
    private ?int $quantity = null;

    #[ORM\Column(type: 'bigint')]
    private ?int $locationId = null;

    #[ORM\Column(length: 100)]
    private ?string $locationType = null;

    #[ORM\Column(length: 100)]
    private ?string $locationFlag = null;

    #[ORM\Column]
    private bool $isSingleton = false;

    #[ORM\Column(nullable: true)]
    private ?bool $isBlueprintCopy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customName = null;

    #[ORM\Column(nullable: true)]
    private ?int $materialEfficiency = null;

    #[ORM\Column(nullable: true)]
    private ?int $timeEfficiency = null;

    #[ORM\Column(nullable: true)]
    private ?int $runs = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCorporationId(): ?int
    {
        return $this->corporationId;
    }

    public function setCorporationId(int $corporationId): static
    {
        $this->corporationId = $corporationId;

        return $this;
    }

    public function getItemId(): ?int
    {
        return $this->itemId;
    }

    public function setItemId(int $itemId): static
    {
        $this->itemId = $itemId;

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

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    public function setLocationId(int $locationId): static
    {
        $this->locationId = $locationId;

        return $this;
    }

    public function getLocationType(): ?string
    {
        return $this->locationType;
    }

    public function setLocationType(string $locationType): static
    {
        $this->locationType = $locationType;

        return $this;
    }

    public function getLocationFlag(): ?string
    {
        return $this->locationFlag;
    }

    public function setLocationFlag(string $locationFlag): static
    {
        $this->locationFlag = $locationFlag;

        return $this;
    }

    public function isSingleton(): bool
    {
        return $this->isSingleton;
    }

    public function setIsSingleton(bool $isSingleton): static
    {
        $this->isSingleton = $isSingleton;

        return $this;
    }

    public function isBlueprintCopy(): ?bool
    {
        return $this->isBlueprintCopy;
    }

    public function setIsBlueprintCopy(?bool $isBlueprintCopy): static
    {
        $this->isBlueprintCopy = $isBlueprintCopy;

        return $this;
    }

    public function getCustomName(): ?string
    {
        return $this->customName;
    }

    public function setCustomName(?string $customName): static
    {
        $this->customName = $customName;

        return $this;
    }

    public function getMaterialEfficiency(): ?int
    {
        return $this->materialEfficiency;
    }

    public function setMaterialEfficiency(?int $materialEfficiency): static
    {
        $this->materialEfficiency = $materialEfficiency;

        return $this;
    }

    public function getTimeEfficiency(): ?int
    {
        return $this->timeEfficiency;
    }

    public function setTimeEfficiency(?int $timeEfficiency): static
    {
        $this->timeEfficiency = $timeEfficiency;

        return $this;
    }

    public function getRuns(): ?int
    {
        return $this->runs;
    }

    public function setRuns(?int $runs): static
    {
        $this->runs = $runs;

        return $this;
    }
}
