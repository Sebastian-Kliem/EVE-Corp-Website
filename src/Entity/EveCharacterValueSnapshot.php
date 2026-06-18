<?php

namespace App\Entity;

use App\Repository\EveCharacterValueSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EveCharacterValueSnapshotRepository::class)]
#[ORM\Table(name: 'eve_character_value_snapshot')]
#[ORM\UniqueConstraint(name: 'uniq_char_val_snapshot_date', columns: ['character_id', 'snapshot_date'])]
#[ORM\Index(columns: ['character_id'])]
#[ORM\Index(columns: ['snapshot_date'])]
class EveCharacterValueSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EveCharacter::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?EveCharacter $character = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $snapshotDate = null;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 2)]
    private ?string $walletBalance = null;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 2)]
    private ?string $assetsValue = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSnapshotDate(): ?\DateTimeImmutable
    {
        return $this->snapshotDate;
    }

    public function setSnapshotDate(\DateTimeImmutable $snapshotDate): static
    {
        $this->snapshotDate = $snapshotDate;

        return $this;
    }

    public function getWalletBalance(): ?string
    {
        return $this->walletBalance;
    }

    public function setWalletBalance(string $walletBalance): static
    {
        $this->walletBalance = $walletBalance;

        return $this;
    }

    public function getAssetsValue(): ?string
    {
        return $this->assetsValue;
    }

    public function setAssetsValue(string $assetsValue): static
    {
        $this->assetsValue = $assetsValue;

        return $this;
    }

    public function getTotalValue(): float
    {
        return (float) $this->walletBalance + (float) $this->assetsValue;
    }
}
