<?php

namespace App\Entity\Fitting;

use App\Entity\User;
use App\Repository\FittingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ship fitting of the corp-wide library, deduplicated by its content hash.
 */
#[ORM\Entity(repositoryClass: FittingRepository::class)]
#[ORM\Table(name: 'fitting')]
#[ORM\UniqueConstraint(name: 'uniq_fitting_content_hash', columns: ['content_hash'])]
class Fitting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private int $shipTypeId = 0;

    // ESI fitting items: list of {typeId, flag, quantity}
    #[ORM\Column(type: Types::JSON)]
    private array $items = [];

    #[ORM\Column(length: 64)]
    private string $contentHash = '';

    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];

    // Manual hint that the fit is stored in the in-game corp fittings (not readable via ESI)
    #[ORM\Column(options: ['default' => false])]
    private bool $corpSaved = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, FittingIngameCopy> */
    #[ORM\OneToMany(targetEntity: FittingIngameCopy::class, mappedBy: 'fitting', cascade: ['remove'])]
    private Collection $ingameCopies;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->ingameCopies = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = mb_substr($name, 0, 255);
        $this->_touch();
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        $this->_touch();
        return $this;
    }

    public function getShipTypeId(): int
    {
        return $this->shipTypeId;
    }

    /**
     * @return list<array{typeId: int, flag: string, quantity: int}>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Ship, items and hash always change together.
     *
     * @param list<array{typeId: int, flag: string, quantity: int}> $items
     */
    public function setContent(int $shipTypeId, array $items, string $contentHash): static
    {
        $this->shipTypeId = $shipTypeId;
        $this->items = $items;
        $this->contentHash = $contentHash;
        $this->_touch();
        return $this;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param list<string> $tags
     */
    public function setTags(array $tags): static
    {
        $this->tags = $tags;
        $this->_touch();
        return $this;
    }

    public function isCorpSaved(): bool
    {
        return $this->corpSaved;
    }

    public function setCorpSaved(bool $corpSaved): static
    {
        $this->corpSaved = $corpSaved;
        $this->_touch();
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, FittingIngameCopy>
     */
    public function getIngameCopies(): Collection
    {
        return $this->ingameCopies;
    }

    private function _touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
