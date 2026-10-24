<?php

namespace App\Entity\Market;

use App\Repository\PublicContractRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Public item exchange contract of any region; contracts that left the ESI list are kept for a while to learn prices.
 */
#[ORM\Entity(repositoryClass: PublicContractRepository::class)]
#[ORM\Table(name: 'public_contract')]
#[ORM\Index(columns: ['region_id', 'gone_since'])]
#[ORM\Index(columns: ['date_expired'])]
class PublicContract
{
    // Contracts that left the ESI list (accepted, expired or deleted) are removed after this many days
    public const GONE_RETENTION_DAYS = 30;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint_int')]
    private ?int $contractId = null;

    #[ORM\Column]
    private ?int $regionId = null;

    #[ORM\Column(type: 'bigint_int')]
    private ?int $startLocationId = null;

    #[ORM\Column(type: 'bigint_int')]
    private ?int $issuerId = null;

    #[ORM\Column(type: 'bigint_int')]
    private ?int $issuerCorporationId = null;

    #[ORM\Column]
    private bool $forCorporation = false;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2)]
    private ?string $price = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 2)]
    private ?string $volume = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateIssued = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateExpired = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $firstSeenAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $goneSince = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $itemsFetchedAt = null;

    /** @var Collection<int, PublicContractItem> */
    #[ORM\OneToMany(targetEntity: PublicContractItem::class, mappedBy: 'contract', cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public function getContractId(): ?int
    {
        return $this->contractId;
    }

    public function setContractId(int $contractId): static
    {
        $this->contractId = $contractId;
        return $this;
    }

    public function getRegionId(): ?int
    {
        return $this->regionId;
    }

    public function setRegionId(int $regionId): static
    {
        $this->regionId = $regionId;
        return $this;
    }

    public function getStartLocationId(): ?int
    {
        return $this->startLocationId;
    }

    public function setStartLocationId(int $startLocationId): static
    {
        $this->startLocationId = $startLocationId;
        return $this;
    }

    public function getIssuerId(): ?int
    {
        return $this->issuerId;
    }

    public function setIssuerId(int $issuerId): static
    {
        $this->issuerId = $issuerId;
        return $this;
    }

    public function getIssuerCorporationId(): ?int
    {
        return $this->issuerCorporationId;
    }

    public function setIssuerCorporationId(int $issuerCorporationId): static
    {
        $this->issuerCorporationId = $issuerCorporationId;
        return $this;
    }

    public function isForCorporation(): bool
    {
        return $this->forCorporation;
    }

    public function setForCorporation(bool $forCorporation): static
    {
        $this->forCorporation = $forCorporation;
        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getVolume(): ?string
    {
        return $this->volume;
    }

    public function setVolume(string $volume): static
    {
        $this->volume = $volume;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDateIssued(): ?\DateTimeImmutable
    {
        return $this->dateIssued;
    }

    public function setDateIssued(\DateTimeImmutable $dateIssued): static
    {
        $this->dateIssued = $dateIssued;
        return $this;
    }

    public function getDateExpired(): ?\DateTimeImmutable
    {
        return $this->dateExpired;
    }

    public function setDateExpired(\DateTimeImmutable $dateExpired): static
    {
        $this->dateExpired = $dateExpired;
        return $this;
    }

    public function getFirstSeenAt(): ?\DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function setFirstSeenAt(\DateTimeImmutable $firstSeenAt): static
    {
        $this->firstSeenAt = $firstSeenAt;
        return $this;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(\DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;
        return $this;
    }

    public function getGoneSince(): ?\DateTimeImmutable
    {
        return $this->goneSince;
    }

    public function setGoneSince(?\DateTimeImmutable $goneSince): static
    {
        $this->goneSince = $goneSince;
        return $this;
    }

    public function getItemsFetchedAt(): ?\DateTimeImmutable
    {
        return $this->itemsFetchedAt;
    }

    public function setItemsFetchedAt(?\DateTimeImmutable $itemsFetchedAt): static
    {
        $this->itemsFetchedAt = $itemsFetchedAt;
        return $this;
    }


    /**
     * @return Collection<int, PublicContractItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(PublicContractItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setContract($this);
        }
        return $this;
    }
}
