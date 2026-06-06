<?php

namespace App\Entity;

use App\Repository\EveCharacterAssetRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EveCharacterAssetRepository::class)]
#[ORM\Index(columns: ['character_id'])]
#[ORM\Index(columns: ['type_id'])]
class EveCharacterAsset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EveCharacter::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?EveCharacter $character = null;

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
}
