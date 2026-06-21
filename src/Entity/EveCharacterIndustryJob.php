<?php

namespace App\Entity;

use App\Repository\EveCharacterIndustryJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EveCharacterIndustryJobRepository::class)]
#[ORM\Table(name: 'eve_character_industry_job')]
#[ORM\Index(columns: ['character_id'])]
#[ORM\Index(columns: ['status'])]
#[ORM\Index(columns: ['end_date'])]
class EveCharacterIndustryJob
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $jobId = null;

    #[ORM\ManyToOne(targetEntity: EveCharacter::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?EveCharacter $character = null;

    #[ORM\Column]
    private ?int $installerId = null;

    #[ORM\Column(type: Types::BIGINT)]
    private ?string $blueprintId = null;

    #[ORM\Column]
    private ?int $blueprintTypeId = null;

    #[ORM\Column(type: Types::BIGINT)]
    private ?string $blueprintLocationId = null;

    #[ORM\Column(type: Types::BIGINT)]
    private ?string $outputLocationId = null;

    #[ORM\Column(nullable: true)]
    private ?int $productTypeId = null;

    #[ORM\Column]
    private ?int $activityId = null;

    #[ORM\Column]
    private ?int $runs = null;

    #[ORM\Column(nullable: true)]
    private ?int $successfulRuns = null;

    #[ORM\Column]
    private ?int $duration = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $pauseDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedDate = null;

    #[ORM\Column(nullable: true)]
    private ?int $completedCharacterId = null;

    #[ORM\Column(length: 50)]
    private ?string $status = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2, nullable: true)]
    private ?string $cost = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $probability = null;

    #[ORM\Column(nullable: true)]
    private ?int $licenceLimit = null;

    public function getJobId(): ?string
    {
        return $this->jobId;
    }

    public function setJobId(string $jobId): static
    {
        $this->jobId = $jobId;
        return $this;
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

    public function getInstallerId(): ?int
    {
        return $this->installerId;
    }

    public function setInstallerId(int $installerId): static
    {
        $this->installerId = $installerId;
        return $this;
    }

    public function getBlueprintId(): ?string
    {
        return $this->blueprintId;
    }

    public function setBlueprintId(string $blueprintId): static
    {
        $this->blueprintId = $blueprintId;
        return $this;
    }

    public function getBlueprintTypeId(): ?int
    {
        return $this->blueprintTypeId;
    }

    public function setBlueprintTypeId(int $blueprintTypeId): static
    {
        $this->blueprintTypeId = $blueprintTypeId;
        return $this;
    }

    public function getBlueprintLocationId(): ?string
    {
        return $this->blueprintLocationId;
    }

    public function setBlueprintLocationId(string $blueprintLocationId): static
    {
        $this->blueprintLocationId = $blueprintLocationId;
        return $this;
    }

    public function getOutputLocationId(): ?string
    {
        return $this->outputLocationId;
    }

    public function setOutputLocationId(string $outputLocationId): static
    {
        $this->outputLocationId = $outputLocationId;
        return $this;
    }

    public function getProductTypeId(): ?int
    {
        return $this->productTypeId;
    }

    public function setProductTypeId(?int $productTypeId): static
    {
        $this->productTypeId = $productTypeId;
        return $this;
    }

    public function getActivityId(): ?int
    {
        return $this->activityId;
    }

    public function setActivityId(int $activityId): static
    {
        $this->activityId = $activityId;
        return $this;
    }

    public function getRuns(): ?int
    {
        return $this->runs;
    }

    public function setRuns(int $runs): static
    {
        $this->runs = $runs;
        return $this;
    }

    public function getSuccessfulRuns(): ?int
    {
        return $this->successfulRuns;
    }

    public function setSuccessfulRuns(?int $successfulRuns): static
    {
        $this->successfulRuns = $successfulRuns;
        return $this;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function setDuration(int $duration): static
    {
        $this->duration = $duration;
        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;
        return $this;
    }

    public function getPauseDate(): ?\DateTimeImmutable
    {
        return $this->pauseDate;
    }

    public function setPauseDate(?\DateTimeImmutable $pauseDate): static
    {
        $this->pauseDate = $pauseDate;
        return $this;
    }

    public function getCompletedDate(): ?\DateTimeImmutable
    {
        return $this->completedDate;
    }

    public function setCompletedDate(?\DateTimeImmutable $completedDate): static
    {
        $this->completedDate = $completedDate;
        return $this;
    }

    public function getCompletedCharacterId(): ?int
    {
        return $this->completedCharacterId;
    }

    public function setCompletedCharacterId(?int $completedCharacterId): static
    {
        $this->completedCharacterId = $completedCharacterId;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCost(): ?string
    {
        return $this->cost;
    }

    public function setCost(?string $cost): static
    {
        $this->cost = $cost;
        return $this;
    }

    public function getProbability(): ?float
    {
        return $this->probability;
    }

    public function setProbability(?float $probability): static
    {
        $this->probability = $probability;
        return $this;
    }

    public function getLicenceLimit(): ?int
    {
        return $this->licenceLimit;
    }

    public function setLicenceLimit(?int $licenceLimit): static
    {
        $this->licenceLimit = $licenceLimit;
        return $this;
    }
}
