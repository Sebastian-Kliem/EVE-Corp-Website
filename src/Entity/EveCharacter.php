<?php

namespace App\Entity;

use App\Repository\EveCharacterRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EveCharacterRepository::class)]
class EveCharacter
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $accessToken = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $refreshToken = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $tokenExpiresAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ownerHash = null;

    #[ORM\Column(nullable: true)]
    private ?int $corporationId = null;

    #[ORM\Column(nullable: true)]
    private ?int $allianceId = null;

    #[ORM\ManyToOne(targetEntity: EveAccount::class, inversedBy: 'characters')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EveAccount $account = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 2, nullable: true)]
    private ?string $walletBalance = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastWalletUpdate = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAssetsUpdate = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastCorpAssetsUpdate = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastMiningUpdate = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastIndustryJobsUpdate = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastKillmailsUpdate = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $performanceCutoffDate = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $tokenValid = true;

    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $roles = [];

    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $skills = [];

    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $skillQueue = [];

    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $attributes = [];

    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $implants = [];

    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $tags = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
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

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function setAccessToken(?string $accessToken): static
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken(?string $refreshToken): static
    {
        $this->refreshToken = $refreshToken;

        return $this;
    }

    public function getTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->tokenExpiresAt;
    }

    public function setTokenExpiresAt(?\DateTimeImmutable $tokenExpiresAt): static
    {
        $this->tokenExpiresAt = $tokenExpiresAt;

        return $this;
    }

    public function getOwnerHash(): ?string
    {
        return $this->ownerHash;
    }

    public function setOwnerHash(?string $ownerHash): static
    {
        $this->ownerHash = $ownerHash;

        return $this;
    }

    public function getCorporationId(): ?int
    {
        return $this->corporationId;
    }

    public function setCorporationId(?int $corporationId): static
    {
        $this->corporationId = $corporationId;

        return $this;
    }

    public function getAllianceId(): ?int
    {
        return $this->allianceId;
    }

    public function setAllianceId(?int $allianceId): static
    {
        $this->allianceId = $allianceId;

        return $this;
    }

    public function getAccount(): ?EveAccount
    {
        return $this->account;
    }

    public function setAccount(?EveAccount $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getWalletBalance(): ?string
    {
        return $this->walletBalance;
    }

    public function setWalletBalance(?string $walletBalance): static
    {
        $this->walletBalance = $walletBalance;

        return $this;
    }

    public function getLastWalletUpdate(): ?\DateTimeImmutable
    {
        return $this->lastWalletUpdate;
    }

    public function setLastWalletUpdate(?\DateTimeImmutable $lastWalletUpdate): static
    {
        $this->lastWalletUpdate = $lastWalletUpdate;

        return $this;
    }

    public function getLastAssetsUpdate(): ?\DateTimeImmutable
    {
        return $this->lastAssetsUpdate;
    }

    public function setLastAssetsUpdate(?\DateTimeImmutable $lastAssetsUpdate): static
    {
        $this->lastAssetsUpdate = $lastAssetsUpdate;

        return $this;
    }

    public function getLastCorpAssetsUpdate(): ?\DateTimeImmutable
    {
        return $this->lastCorpAssetsUpdate;
    }

    public function setLastCorpAssetsUpdate(?\DateTimeImmutable $lastCorpAssetsUpdate): static
    {
        $this->lastCorpAssetsUpdate = $lastCorpAssetsUpdate;

        return $this;
    }

    public function getLastMiningUpdate(): ?\DateTimeImmutable
    {
        return $this->lastMiningUpdate;
    }

    public function setLastMiningUpdate(?\DateTimeImmutable $lastMiningUpdate): static
    {
        $this->lastMiningUpdate = $lastMiningUpdate;

        return $this;
    }

    public function getLastIndustryJobsUpdate(): ?\DateTimeImmutable
    {
        return $this->lastIndustryJobsUpdate;
    }

    public function setLastIndustryJobsUpdate(?\DateTimeImmutable $lastIndustryJobsUpdate): static
    {
        $this->lastIndustryJobsUpdate = $lastIndustryJobsUpdate;

        return $this;
    }

    public function getLastKillmailsUpdate(): ?\DateTimeImmutable
    {
        return $this->lastKillmailsUpdate;
    }

    public function setLastKillmailsUpdate(?\DateTimeImmutable $lastKillmailsUpdate): static
    {
        $this->lastKillmailsUpdate = $lastKillmailsUpdate;

        return $this;
    }

    public function getPerformanceCutoffDate(): ?\DateTimeImmutable
    {
        return $this->performanceCutoffDate;
    }

    public function setPerformanceCutoffDate(?\DateTimeImmutable $performanceCutoffDate): static
    {
        $this->performanceCutoffDate = $performanceCutoffDate;

        return $this;
    }

    public function isTokenValid(): bool
    {
        return $this->tokenValid;
    }

    public function setTokenValid(bool $tokenValid): static
    {
        $this->tokenValid = $tokenValid;

        return $this;
    }

    public function getRoles(): array
    {
        return $this->roles ?? [];
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getSkills(): array
    {
        return $this->skills ?? [];
    }

    public function setSkills(array $skills): static
    {
        $this->skills = $skills;

        return $this;
    }

    public function getSkillQueue(): array
    {
        return $this->skillQueue ?? [];
    }

    public function setSkillQueue(array $skillQueue): static
    {
        $this->skillQueue = $skillQueue;

        return $this;
    }

    public function getAttributes(): array
    {
        return $this->attributes ?? [];
    }

    public function setAttributes(array $attributes): static
    {
        $this->attributes = $attributes;

        return $this;
    }

    public function getImplants(): array
    {
        return $this->implants ?? [];
    }

    public function setImplants(array $implants): static
    {
        $this->implants = $implants;

        return $this;
    }

    public const PREDEFINED_TAGS = ['Skill-Extractor-Farm', 'PI', 'Industrie', 'Mining', 'Combat', 'Trading'];

    public function getTags(): array
    {
        return $this->tags ?? [];
    }

    public function setTags(array $tags): static
    {
        $this->tags = $tags;

        return $this;
    }

    public static function getPredefinedTags(): array
    {
        return self::PREDEFINED_TAGS;
    }

    public function isDirector(): bool
    {
        return in_array('Director', $this->getRoles(), true);
    }
}
