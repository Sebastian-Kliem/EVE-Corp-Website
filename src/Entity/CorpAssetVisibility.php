<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'corp_asset_visibility')]
class CorpAssetVisibility
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint')]
    private ?string $locationId = null;

    #[ORM\Column(length: 100)]
    private ?string $locationFlag = null;

    #[ORM\Column]
    private bool $isVisible = false;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getLocationFlag(): ?string
    {
        return $this->locationFlag;
    }

    public function setLocationFlag(string $locationFlag): static
    {
        $this->locationFlag = $locationFlag;
        return $this;
    }

    public function isVisible(): bool
    {
        return $this->isVisible;
    }

    public function setIsVisible(bool $isVisible): static
    {
        $this->isVisible = $isVisible;
        return $this;
    }
}
