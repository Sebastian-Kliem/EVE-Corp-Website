<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_USERNAME', fields: ['username'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $username = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var Collection<int, EveAccount>
     */
    #[ORM\OneToMany(targetEntity: EveAccount::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $eveAccounts;

    #[ORM\Column(type: 'json')]
    private array $personalCorpHangars = [];

    #[ORM\Column(type: 'json')]
    private array $personalCorpContainers = [];

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $shareBlueprints = false;

    public function __construct()
    {
        $this->eveAccounts = new ArrayCollection();
        $this->personalCorpHangars = [];
        $this->personalCorpContainers = [];
    }



    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_RECRUIT
        if (empty($roles)) {
            $roles[] = 'ROLE_RECRUIT';
        }

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getDisplayName(): string
    {
        return (string) $this->username;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data["\0".self::class."\0id"] ?? null;
        $this->username = $data["\0".self::class."\0username"] ?? null;
        $this->roles = $data["\0".self::class."\0roles"] ?? [];
        $this->password = $data["\0".self::class."\0password"] ?? null;
        $this->personalCorpHangars = $data["\0".self::class."\0personalCorpHangars"] ?? [];
        $this->personalCorpContainers = $data["\0".self::class."\0personalCorpContainers"] ?? [];
        $this->shareBlueprints = $data["\0".self::class."\0shareBlueprints"] ?? false;

        if (isset($data["\0".self::class."\0eveAccounts"])) {
            $this->eveAccounts = $data["\0".self::class."\0eveAccounts"];
        } else {
            $this->eveAccounts = new ArrayCollection();
        }
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // @deprecated, to be removed when upgrading to Symfony 8
    }

    /**
     * @return Collection<int, EveAccount>
     */
    public function getEveAccounts(): Collection
    {
        return $this->eveAccounts;
    }

    public function addEveAccount(EveAccount $eveAccount): static
    {
        if (!$this->eveAccounts->contains($eveAccount)) {
            $this->eveAccounts->add($eveAccount);
            $eveAccount->setUser($this);
        }

        return $this;
    }

    public function removeEveAccount(EveAccount $eveAccount): static
    {
        if ($this->eveAccounts->removeElement($eveAccount)) {
            // set the owning side to null (unless already changed)
            if ($eveAccount->getUser() === $this) {
                $eveAccount->setUser(null);
            }
        }

        return $this;
    }

    public function getPersonalCorpHangars(): array
    {
        return $this->personalCorpHangars ?? [];
    }

    public function setPersonalCorpHangars(array $personalCorpHangars): static
    {
        $this->personalCorpHangars = $personalCorpHangars;
        return $this;
    }

    public function getPersonalCorpContainers(): array
    {
        return $this->personalCorpContainers ?? [];
    }

    public function setPersonalCorpContainers(array $personalCorpContainers): static
    {
        $this->personalCorpContainers = $personalCorpContainers;
        return $this;
    }

    public function isShareBlueprints(): bool
    {
        return $this->shareBlueprints ?? false;
    }

    public function setShareBlueprints(bool $shareBlueprints): static
    {
        $this->shareBlueprints = $shareBlueprints;
        return $this;
    }
}
