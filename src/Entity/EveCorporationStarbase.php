<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'eve_corporation_starbase')]
class EveCorporationStarbase
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    private ?string $id = null; // Represented as string in PHP to avoid 64-bit integer overflow

    #[ORM\Column(type: 'bigint')]
    private ?string $corporationId = null;

    #[ORM\Column]
    private ?int $typeId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $typeName = null;

    #[ORM\Column]
    private ?int $solarSystemId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $solarSystemName = null;

    #[ORM\Column(length: 50)]
    private ?string $state = null; // offline, online, reinforced, unanchoring

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $onlinedSince = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reinforcedUntil = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $fuels = null; // fuel types and quantities

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $modules = null; // fitted or anchored POS modules

    #[ORM\Column]
    private ?\DateTimeImmutable $lastUpdated = null;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getCorporationId(): ?string
    {
        return $this->corporationId;
    }

    public function setCorporationId(string $corporationId): static
    {
        $this->corporationId = $corporationId;
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

    public function getTypeName(): ?string
    {
        return $this->typeName;
    }

    public function setTypeName(?string $typeName): static
    {
        $this->typeName = $typeName;
        return $this;
    }

    public function getSolarSystemId(): ?int
    {
        return $this->solarSystemId;
    }

    public function setSolarSystemId(int $solarSystemId): static
    {
        $this->solarSystemId = $solarSystemId;
        return $this;
    }

    public function getSolarSystemName(): ?string
    {
        return $this->solarSystemName;
    }

    public function setSolarSystemName(?string $solarSystemName): static
    {
        $this->solarSystemName = $solarSystemName;
        return $this;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(string $state): static
    {
        $this->state = $state;
        return $this;
    }

    public function getOnlinedSince(): ?\DateTimeImmutable
    {
        return $this->onlinedSince;
    }

    public function setOnlinedSince(?\DateTimeImmutable $onlinedSince): static
    {
        $this->onlinedSince = $onlinedSince;
        return $this;
    }

    public function getReinforcedUntil(): ?\DateTimeImmutable
    {
        return $this->reinforcedUntil;
    }

    public function setReinforcedUntil(?\DateTimeImmutable $reinforcedUntil): static
    {
        $this->reinforcedUntil = $reinforcedUntil;
        return $this;
    }

    public function getFuels(): ?array
    {
        return $this->fuels;
    }

    public function setFuels(?array $fuels): static
    {
        $this->fuels = $fuels;
        return $this;
    }

    public function getModules(): ?array
    {
        return $this->modules;
    }

    public function setModules(?array $modules): static
    {
        $this->modules = $modules;
        return $this;
    }

    public function getLastUpdated(): ?\DateTimeImmutable
    {
        return $this->lastUpdated;
    }

    public function setLastUpdated(\DateTimeImmutable $lastUpdated): static
    {
        $this->lastUpdated = $lastUpdated;
        return $this;
    }
}
