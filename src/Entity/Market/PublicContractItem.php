<?php

namespace App\Entity\Market;

use App\Repository\PublicContractItemRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Item of a public contract; not included items are what the issuer asks for in exchange.
 */
#[ORM\Entity(repositoryClass: PublicContractItemRepository::class)]
#[ORM\Table(name: 'public_contract_item')]
#[ORM\UniqueConstraint(name: 'uniq_public_contract_item_record', columns: ['contract_id', 'record_id'])]
#[ORM\Index(columns: ['type_id'])]
class PublicContractItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint_int')]
    private ?int $recordId = null;

    #[ORM\Column]
    private ?int $typeId = null;

    #[ORM\Column(type: 'bigint_int')]
    private ?int $quantity = null;

    #[ORM\Column]
    private bool $isIncluded = true;

    #[ORM\Column]
    private bool $isSingleton = false;

    #[ORM\Column(nullable: true)]
    private ?bool $isBlueprintCopy = null;

    #[ORM\Column(nullable: true)]
    private ?int $materialEfficiency = null;

    #[ORM\Column(nullable: true)]
    private ?int $timeEfficiency = null;

    #[ORM\Column(nullable: true)]
    private ?int $runs = null;

    #[ORM\Column(type: 'bigint_int', nullable: true)]
    private ?int $itemId = null;

    #[ORM\ManyToOne(targetEntity: PublicContract::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'contract_id', referencedColumnName: 'contract_id', nullable: false, onDelete: 'CASCADE')]
    private ?PublicContract $contract = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecordId(): ?int
    {
        return $this->recordId;
    }

    public function setRecordId(int $recordId): static
    {
        $this->recordId = $recordId;
        return $this;
    }

    public function getTypeId(): ?int
    {
        return $this->typeId;
    }

    public function setTypeId(int $typeId): static
    {
        $this->typeId = $typeId;
        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function isIncluded(): bool
    {
        return $this->isIncluded;
    }

    public function setIsIncluded(bool $isIncluded): static
    {
        $this->isIncluded = $isIncluded;
        return $this;
    }

    public function isSingleton(): bool
    {
        return $this->isSingleton;
    }

    public function setIsSingleton(bool $isSingleton): static
    {
        $this->isSingleton = $isSingleton;
        return $this;
    }

    public function isBlueprintCopy(): ?bool
    {
        return $this->isBlueprintCopy;
    }

    public function setIsBlueprintCopy(?bool $isBlueprintCopy): static
    {
        $this->isBlueprintCopy = $isBlueprintCopy;
        return $this;
    }

    public function getMaterialEfficiency(): ?int
    {
        return $this->materialEfficiency;
    }

    public function setMaterialEfficiency(?int $materialEfficiency): static
    {
        $this->materialEfficiency = $materialEfficiency;
        return $this;
    }

    public function getTimeEfficiency(): ?int
    {
        return $this->timeEfficiency;
    }

    public function setTimeEfficiency(?int $timeEfficiency): static
    {
        $this->timeEfficiency = $timeEfficiency;
        return $this;
    }

    public function getRuns(): ?int
    {
        return $this->runs;
    }

    public function setRuns(?int $runs): static
    {
        $this->runs = $runs;
        return $this;
    }

    public function getItemId(): ?int
    {
        return $this->itemId;
    }

    public function setItemId(?int $itemId): static
    {
        $this->itemId = $itemId;
        return $this;
    }


    public function getContract(): ?PublicContract
    {
        return $this->contract;
    }

    public function setContract(?PublicContract $contract): static
    {
        $this->contract = $contract;
        return $this;
    }
}
