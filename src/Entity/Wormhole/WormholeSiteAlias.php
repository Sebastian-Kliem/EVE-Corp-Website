<?php

namespace App\Entity\Wormhole;

use App\Entity\User;
use App\Repository\WormholeSiteAliasRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Site name from a pasted probe scanner list that was mapped to a known site by a user.
 */
#[ORM\Entity(repositoryClass: WormholeSiteAliasRepository::class)]
#[ORM\Table(name: 'wormhole_site_alias')]
#[ORM\UniqueConstraint(columns: ['alias'])]
class WormholeSiteAlias
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private ?string $alias = null;

    #[ORM\ManyToOne(targetEntity: WormholeSite::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?WormholeSite $site = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAlias(): ?string
    {
        return $this->alias;
    }

    public function setAlias(string $alias): static
    {
        $this->alias = $alias;
        return $this;
    }

    public function getSite(): ?WormholeSite
    {
        return $this->site;
    }

    public function setSite(WormholeSite $site): static
    {
        $this->site = $site;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
