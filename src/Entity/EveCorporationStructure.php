<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'eve_corporation_structure')]
class EveCorporationStructure
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    private ?string $id = null; // Represented as string in PHP to avoid 64-bit integer overflow

    #[ORM\Column(type: 'bigint')]
    private ?string $corporationId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column]
    private ?int $typeId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $typeName = null;

    #[ORM\Column]
    private ?int $solarSystemId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $solarSystemName = null;

    #[ORM\Column(length: 50)]
    private ?string $state = null; // e.g. online, offline, anchoring, reinforced

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $fuelExpires = null;

    #[ORM\Column(type: 'json')]
    private array $services = [];

    #[ORM\Column(nullable: true)]
    private ?int $reinforceHour = null;

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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
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

    public function getFuelExpires(): ?\DateTimeImmutable
    {
        return $this->fuelExpires;
    }

    public function setFuelExpires(?\DateTimeImmutable $fuelExpires): static
    {
        $this->fuelExpires = $fuelExpires;
        return $this;
    }

    public function getServices(): array
    {
        return $this->services;
    }

    public function setServices(array $services): static
    {
        $this->services = $services;
        return $this;
    }

    public function getReinforceHour(): ?int
    {
        return $this->reinforceHour;
    }

    public function setReinforceHour(?int $reinforceHour): static
    {
        $this->reinforceHour = $reinforceHour;
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
