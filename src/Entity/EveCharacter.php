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
}
