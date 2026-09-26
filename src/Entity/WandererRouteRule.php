<?php

namespace App\Entity;

use App\Repository\WandererRouteRuleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WandererRouteRuleRepository::class)]
#[ORM\Table(name: 'wanderer_route_rule')]
#[ORM\HasLifecycleCallbacks]
class WandererRouteRule
{
    public const SEC_MODE_HIGHSEC_ONLY = 'highsec_only';
    public const SEC_MODE_HIGHSEC_LOWSEC = 'highsec_lowsec';
    public const SEC_MODE_ANY = 'any';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 100)]
    private string $name = '';

    #[ORM\Column]
    private int $targetSolarSystemId = 30000142; // Default Jita

    #[ORM\Column(length: 100)]
    private string $targetSolarSystemName = 'Jita';

    #[ORM\Column]
    private int $maxJumps = 10;

    #[ORM\Column(length: 30)]
    private string $securityMode = self::SEC_MODE_HIGHSEC_ONLY;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column]
    private int $cooldownMinutes = 180;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastTriggeredAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function isCorpRule(): bool
    {
        return $this->user === null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getTargetSolarSystemId(): int
    {
        return $this->targetSolarSystemId;
    }

    public function setTargetSolarSystemId(int $targetSolarSystemId): self
    {
        $this->targetSolarSystemId = $targetSolarSystemId;
        return $this;
    }

    public function getTargetSolarSystemName(): string
    {
        return $this->targetSolarSystemName;
    }

    public function setTargetSolarSystemName(string $targetSolarSystemName): self
    {
        $this->targetSolarSystemName = $targetSolarSystemName;
        return $this;
    }

    public function getMaxJumps(): int
    {
        return $this->maxJumps;
    }

    public function setMaxJumps(int $maxJumps): self
    {
        $this->maxJumps = $maxJumps;
        return $this;
    }

    public function getSecurityMode(): string
    {
        return $this->securityMode;
    }

    public function setSecurityMode(string $securityMode): self
    {
        $this->securityMode = $securityMode;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCooldownMinutes(): int
    {
        return $this->cooldownMinutes;
    }

    public function setCooldownMinutes(int $cooldownMinutes): self
    {
        $this->cooldownMinutes = $cooldownMinutes;
        return $this;
    }

    public function getLastTriggeredAt(): ?\DateTimeImmutable
    {
        return $this->lastTriggeredAt;
    }

    public function setLastTriggeredAt(?\DateTimeImmutable $lastTriggeredAt): self
    {
        $this->lastTriggeredAt = $lastTriggeredAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
