<?php

namespace App\Entity;

use App\Repository\TrackedCorporationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A corporation whose structures may be synced. New corporations stay pending until a CEO decides.
 */
#[ORM\Entity(repositoryClass: TrackedCorporationRepository::class)]
#[ORM\Table(name: 'tracked_corporation')]
class TrackedCorporation
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    private string $id;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $ticker = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    // Only the home corporation posts to the global Discord channels
    #[ORM\Column]
    private bool $useGlobalChannels = false;

    #[ORM\Column(type: 'encrypted_text', nullable: true)]
    private ?string $webhookUrl = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $pingRole = null;

    #[ORM\Column]
    private \DateTimeImmutable $discoveredAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    public function __construct(string $id)
    {
        $this->id = $id;
        $this->discoveredAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
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

    public function getTicker(): ?string
    {
        return $this->ticker;
    }

    public function setTicker(?string $ticker): static
    {
        $this->ticker = $ticker;
        return $this;
    }

    public function getDisplayName(): string
    {
        $name = $this->name ?? sprintf('Corporation #%s', $this->id);

        return $this->ticker ? sprintf('%s [%s]', $name, $this->ticker) : $name;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        $this->decidedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function usesGlobalChannels(): bool
    {
        return $this->useGlobalChannels;
    }

    public function setUseGlobalChannels(bool $useGlobalChannels): static
    {
        $this->useGlobalChannels = $useGlobalChannels;
        return $this;
    }

    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    public function setWebhookUrl(?string $webhookUrl): static
    {
        $this->webhookUrl = $webhookUrl;
        return $this;
    }

    public function getPingRole(): ?string
    {
        return $this->pingRole;
    }

    public function setPingRole(?string $pingRole): static
    {
        $this->pingRole = $pingRole;
        return $this;
    }

    public function getDiscoveredAt(): \DateTimeImmutable
    {
        return $this->discoveredAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }
}
