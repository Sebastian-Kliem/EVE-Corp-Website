<?php

namespace App\Entity\Fitting;

use App\Entity\EveCharacter;
use App\Repository\FittingIngameCopyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A fitting saved in the in-game personal fittings of a character.
 */
#[ORM\Entity(repositoryClass: FittingIngameCopyRepository::class)]
#[ORM\Table(name: 'fitting_ingame_copy')]
#[ORM\UniqueConstraint(name: 'uniq_fitting_copy_character_esi', columns: ['character_id', 'esi_fitting_id'])]
class FittingIngameCopy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Fitting::class, inversedBy: 'ingameCopies')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Fitting $fitting;

    #[ORM\ManyToOne(targetEntity: EveCharacter::class)]
    #[ORM\JoinColumn(name: 'character_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private EveCharacter $character;

    #[ORM\Column]
    private int $esiFittingId;

    #[ORM\Column(length: 255)]
    private string $ingameName = '';

    // Hash of the in-game content; differs from the fitting after a website edit
    #[ORM\Column(length: 64)]
    private string $contentHash = '';

    #[ORM\Column]
    private \DateTimeImmutable $syncedAt;

    public function __construct(Fitting $fitting, EveCharacter $character, int $esiFittingId)
    {
        $this->fitting = $fitting;
        $this->character = $character;
        $this->esiFittingId = $esiFittingId;
        $this->syncedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFitting(): Fitting
    {
        return $this->fitting;
    }

    public function setFitting(Fitting $fitting): static
    {
        $this->fitting = $fitting;
        return $this;
    }

    public function getCharacter(): EveCharacter
    {
        return $this->character;
    }

    public function getEsiFittingId(): int
    {
        return $this->esiFittingId;
    }

    public function setEsiFittingId(int $esiFittingId): static
    {
        $this->esiFittingId = $esiFittingId;
        return $this;
    }

    public function getIngameName(): string
    {
        return $this->ingameName;
    }

    public function setIngameName(string $ingameName): static
    {
        $this->ingameName = mb_substr($ingameName, 0, 255);
        return $this;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function setContentHash(string $contentHash): static
    {
        $this->contentHash = $contentHash;
        return $this;
    }

    public function isOutdated(): bool
    {
        return $this->contentHash !== $this->fitting->getContentHash();
    }

    public function getSyncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(\DateTimeImmutable $syncedAt): static
    {
        $this->syncedAt = $syncedAt;
        return $this;
    }
}
