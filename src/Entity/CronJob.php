<?php

namespace App\Entity;

use App\Repository\CronJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CronJobRepository::class)]
class CronJob
{
    public const STATUS_RUNNING = 'running';
    public const RUN_TIMEOUT_SECONDS = 3600;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $command = null;

    #[ORM\Column(length: 255)]
    private ?string $cronExpression = '*/10 * * * *';

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $nextRunAt = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $lastExecutionTime = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $lastStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

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

    public function getCommand(): ?string
    {
        return $this->command;
    }

    public function setCommand(string $command): static
    {
        $this->command = $command;

        return $this;
    }

    public function getCronExpression(): ?string
    {
        return $this->cronExpression;
    }

    public function setCronExpression(string $cronExpression): static
    {
        $this->cronExpression = $cronExpression;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?\DateTimeImmutable $lastRunAt): static
    {
        $this->lastRunAt = $lastRunAt;

        return $this;
    }

    public function getNextRunAt(): ?\DateTimeImmutable
    {
        return $this->nextRunAt;
    }

    public function setNextRunAt(?\DateTimeImmutable $nextRunAt): static
    {
        $this->nextRunAt = $nextRunAt;

        return $this;
    }

    public function getLastExecutionTime(): ?float
    {
        return $this->lastExecutionTime;
    }

    public function setLastExecutionTime(?float $lastExecutionTime): static
    {
        $this->lastExecutionTime = $lastExecutionTime;

        return $this;
    }

    public function getLastStatus(): ?string
    {
        return $this->lastStatus;
    }

    public function setLastStatus(?string $lastStatus): static
    {
        $this->lastStatus = $lastStatus;

        return $this;
    }

    // A run is considered dead once it outlives the scheduler's job lock TTL
    public function isRunning(?\DateTimeImmutable $now = null): bool
    {
        if ($this->lastStatus !== self::STATUS_RUNNING || $this->lastRunAt === null) {
            return false;
        }

        $now ??= new \DateTimeImmutable();

        return $now->getTimestamp() - $this->lastRunAt->getTimestamp() < self::RUN_TIMEOUT_SECONDS;
    }

    public function isRunAborted(?\DateTimeImmutable $now = null): bool
    {
        return $this->lastStatus === self::STATUS_RUNNING && !$this->isRunning($now);
    }

    public function isDue(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return $this->isActive && ($this->nextRunAt === null || $this->nextRunAt <= $now);
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): static
    {
        $this->lastError = $lastError;

        return $this;
    }
}
