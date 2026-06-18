<?php

namespace App\Entity;

use App\Repository\TrackingListItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TrackingListItemRepository::class)]
#[ORM\Table(name: 'tracking_list_item')]
#[ORM\UniqueConstraint(name: 'uniq_list_item', columns: ['tracking_list_id', 'type_id'])]
class TrackingListItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TrackingList::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TrackingList $trackingList = null;

    #[ORM\Column]
    private ?int $typeId = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrackingList(): ?TrackingList
    {
        return $this->trackingList;
    }

    public function setTrackingList(?TrackingList $trackingList): static
    {
        $this->trackingList = $trackingList;

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
}
