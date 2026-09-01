<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'discord_notification_log')]
#[ORM\Index(columns: ['notification_id'], name: 'idx_discord_notif_id')]
#[ORM\Index(columns: ['entity_type', 'entity_id'], name: 'idx_discord_entity')]
#[ORM\Index(columns: ['created_at'], name: 'idx_discord_created_at')]
class DiscordNotificationLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $notificationId = null;

    #[ORM\Column(length: 50)]
    private string $channel = 'default';

    #[ORM\Column(length: 100)]
    private string $type;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $entityType = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $entityId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $alertLevel = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNotificationId(): ?string
    {
        return $this->notificationId;
    }

    public function setNotificationId(?string $notificationId): static
    {
        $this->notificationId = $notificationId;
        return $this;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): static
    {
        $this->channel = $channel;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function setEntityType(?string $entityType): static
    {
        $this->entityType = $entityType;
        return $this;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    public function setEntityId(?string $entityId): static
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getAlertLevel(): ?string
    {
        return $this->alertLevel;
    }

    public function setAlertLevel(?string $alertLevel): static
    {
        $this->alertLevel = $alertLevel;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
