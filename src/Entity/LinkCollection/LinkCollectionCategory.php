<?php

namespace App\Entity\LinkCollection;

use App\Repository\LinkCollectionCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LinkCollectionCategoryRepository::class)]
class LinkCollectionCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $Name = null;

    /**
     * @var Collection<int, LinkCollectionItem>
     */
    #[ORM\OneToMany(targetEntity: LinkCollectionItem::class, mappedBy: 'Category')]
    private Collection $linkCollectionItems;

    public function __construct()
    {
        $this->linkCollectionItems = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->Name;
    }

    public function setName(string $Name): static
    {
        $this->Name = $Name;

        return $this;
    }

    /**
     * @return Collection<int, LinkCollectionItem>
     */
    public function getLinkCollectionItems(): Collection
    {
        return $this->linkCollectionItems;
    }

    public function addLinkCollectionItem(LinkCollectionItem $linkCollectionItem): static
    {
        if (!$this->linkCollectionItems->contains($linkCollectionItem)) {
            $this->linkCollectionItems->add($linkCollectionItem);
            $linkCollectionItem->setCategory($this);
        }

        return $this;
    }

    public function removeLinkCollectionItem(LinkCollectionItem $linkCollectionItem): static
    {
        if ($this->linkCollectionItems->removeElement($linkCollectionItem)) {
            // set the owning side to null (unless already changed)
            if ($linkCollectionItem->getCategory() === $this) {
                $linkCollectionItem->setCategory(null);
            }
        }

        return $this;
    }
}
