<?php

namespace App\Entity;

use App\Repository\EveCharacterAssetSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EveCharacterAssetSnapshotRepository::class)]
#[ORM\Table(name: 'eve_character_asset_snapshot')]
#[ORM\UniqueConstraint(name: 'uniq_char_snapshot_date', columns: ['character_id', 'snapshot_date'])]
#[ORM\Index(columns: ['character_id'])]
#[ORM\Index(columns: ['snapshot_date'])]
class EveCharacterAssetSnapshot
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

    #[ORM\Column(type: Types::JSON)]
    private array $assetsData = [];

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

    public function getAssetsData(): array
    {
        return $this->assetsData;
    }

    public function setAssetsData(array $assetsData): static
    {
        $this->assetsData = $assetsData;

        return $this;
    }
}
