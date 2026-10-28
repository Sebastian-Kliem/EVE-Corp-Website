<?php

namespace App\Entity\Wormhole;

use App\Repository\WormholeSiteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cosmic signature or anomaly in wormhole space (sources: triff.tools, EVE Sister Probe Wiki).
 */
#[ORM\Entity(repositoryClass: WormholeSiteRepository::class)]
#[ORM\Table(name: 'wormhole_site')]
#[ORM\UniqueConstraint(columns: ['name'])]
class WormholeSite
{
    public const CATEGORY_COMBAT = 'combat';
    public const CATEGORY_DATA = 'data';
    public const CATEGORY_RELIC = 'relic';
    public const CATEGORY_GAS = 'gas';
    public const CATEGORY_ORE = 'ore';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private ?string $name = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $nameDe = null;

    #[ORM\Column(length: 16)]
    private ?string $category = null;

    /** Wormhole classes the site spawns in, e.g. ["C5", "C6", "Shattered"] */
    #[ORM\Column(type: Types::JSON)]
    private array $classes = [];

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $occurrence = null;

    #[ORM\Column(type: 'bigint_int', nullable: true)]
    private ?int $blueLootIsk = null;

    /** Waves with their NPC ships (qty, name, typeId, dps, alpha, ehp, sig, special, trigger flags) */
    #[ORM\Column(type: Types::JSON)]
    private array $waves = [];

    /** Ore, ice or gas inside the site (qty, name, typeId, amount, unit) */
    #[ORM\Column(type: Types::JSON)]
    private array $resources = [];

    #[ORM\Column(type: Types::JSON)]
    private array $notes = [];

    #[ORM\Column(length: 64)]
    private ?string $source = null;

    #[ORM\Column]
    private int $sortOrder = 0;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getNameDe(): ?string
    {
        return $this->nameDe;
    }

    public function setNameDe(?string $nameDe): static
    {
        $this->nameDe = $nameDe;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getClasses(): array
    {
        return $this->classes;
    }

    public function setClasses(array $classes): static
    {
        $this->classes = $classes;
        return $this;
    }

    public function getOccurrence(): ?string
    {
        return $this->occurrence;
    }

    public function setOccurrence(?string $occurrence): static
    {
        $this->occurrence = $occurrence;
        return $this;
    }

    public function getBlueLootIsk(): ?int
    {
        return $this->blueLootIsk;
    }

    public function setBlueLootIsk(?int $blueLootIsk): static
    {
        $this->blueLootIsk = $blueLootIsk;
        return $this;
    }

    public function getWaves(): array
    {
        return $this->waves;
    }

    public function setWaves(array $waves): static
    {
        $this->waves = $waves;
        return $this;
    }

    public function getResources(): array
    {
        return $this->resources;
    }

    public function setResources(array $resources): static
    {
        $this->resources = $resources;
        return $this;
    }

    public function getNotes(): array
    {
        return $this->notes;
    }

    public function setNotes(array $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
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
