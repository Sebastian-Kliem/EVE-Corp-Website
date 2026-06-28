<?php

namespace App\Entity;

use App\Repository\EveAccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EveAccountRepository::class)]
class EveAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column]
    private bool $isOmega = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $groupName = null;



    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'eveAccounts')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    /**
     * @var Collection<int, EveCharacter>
     */
    #[ORM\OneToMany(targetEntity: EveCharacter::class, mappedBy: 'account')]
    private Collection $characters;

    public function __construct()
    {
        $this->characters = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function isOmega(): bool
    {
        return $this->isOmega;
    }

    public function setIsOmega(bool $isOmega): static
    {
        $this->isOmega = $isOmega;

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

    /**
     * @return Collection<int, EveCharacter>
     */
    public function getCharacters(): Collection
    {
        return $this->characters;
    }

    public function addCharacter(EveCharacter $character): static
    {
        if (!$this->characters->contains($character)) {
            $this->characters->add($character);
            $character->setAccount($this);
        }

        return $this;
    }

    public function removeCharacter(EveCharacter $character): static
    {
        if ($this->characters->removeElement($character)) {
            // set the owning side to null (unless already changed)
            if ($character->getAccount() === $this) {
                $character->setAccount(null);
            }
        }

        return $this;
    }

    public function getGroupName(): ?string
    {
        return $this->groupName;
    }

    public function setGroupName(?string $groupName): static
    {
        $this->groupName = $groupName;

        return $this;
    }
}
