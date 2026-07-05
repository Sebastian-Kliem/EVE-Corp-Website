<?php

namespace App\Entity;

use App\Repository\EveKillmailRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EveKillmailRepository::class)]
#[ORM\Table(name: 'eve_killmail')]
#[ORM\UniqueConstraint(name: 'uniq_char_killmail_id', columns: ['character_id', 'killmail_id'])]
#[ORM\Index(columns: ['character_id'])]
#[ORM\Index(columns: ['killmail_time'])]
class EveKillmail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EveCharacter::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?EveCharacter $character = null;

    #[ORM\Column(type: Types::BIGINT)]
    private ?string $killmailId = null;

    #[ORM\Column(length: 100)]
    private ?string $killmailHash = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $killmailTime = null;

    #[ORM\Column]
    private ?int $solarSystemId = null;

    #[ORM\Column(nullable: true)]
    private ?int $victimCharacterId = null;

    #[ORM\Column(nullable: true)]
    private ?int $victimCorporationId = null;

    #[ORM\Column(nullable: true)]
    private ?int $victimAllianceId = null;

    #[ORM\Column(nullable: true)]
    private ?int $victimShipTypeId = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isLoss = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isKill = false;

    #[ORM\Column(type: Types::JSON)]
    private array $data = [];

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

    public function getKillmailId(): ?string
    {
        return $this->killmailId;
    }

    public function setKillmailId(string $killmailId): static
    {
        $this->killmailId = $killmailId;
        return $this;
    }

    public function getKillmailHash(): ?string
    {
        return $this->killmailHash;
    }

    public function setKillmailHash(string $killmailHash): static
    {
        $this->killmailHash = $killmailHash;
        return $this;
    }

    public function getKillmailTime(): ?\DateTimeImmutable
    {
        return $this->killmailTime;
    }

    public function setKillmailTime(\DateTimeImmutable $killmailTime): static
    {
        $this->killmailTime = $killmailTime;
        return $this;
    }

    public function getSolarSystemId(): ?int
    {
        return $this->solarSystemId;
    }

    public function setSolarSystemId(int $solarSystemId): static
    {
        $this->solarSystemId = $solarSystemId;
        return $this;
    }

    public function getVictimCharacterId(): ?int
    {
        return $this->victimCharacterId;
    }

    public function setVictimCharacterId(?int $victimCharacterId): static
    {
        $this->victimCharacterId = $victimCharacterId;
        return $this;
    }

    public function getVictimCorporationId(): ?int
    {
        return $this->victimCorporationId;
    }

    public function setVictimCorporationId(?int $victimCorporationId): static
    {
        $this->victimCorporationId = $victimCorporationId;
        return $this;
    }

    public function getVictimAllianceId(): ?int
    {
        return $this->victimAllianceId;
    }

    public function setVictimAllianceId(?int $victimAllianceId): static
    {
        $this->victimAllianceId = $victimAllianceId;
        return $this;
    }

    public function getVictimShipTypeId(): ?int
    {
        return $this->victimShipTypeId;
    }

    public function setVictimShipTypeId(?int $victimShipTypeId): static
    {
        $this->victimShipTypeId = $victimShipTypeId;
        return $this;
    }

    public function isLoss(): bool
    {
        return $this->isLoss;
    }

    public function setIsLoss(bool $isLoss): static
    {
        $this->isLoss = $isLoss;
        return $this;
    }

    public function isKill(): bool
    {
        return $this->isKill;
    }

    public function setIsKill(bool $isKill): static
    {
        $this->isKill = $isKill;
        return $this;
    }

    public function getData(): array
    {
        return $this->data ?? [];
    }

    public function setData(array $data): static
    {
        $this->data = $data;
        return $this;
    }

    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'character' => $this->character,
            'killmailId' => $this->killmailId,
            'killmailHash' => $this->killmailHash,
            'killmailTime' => $this->killmailTime,
            'solarSystemId' => $this->solarSystemId,
            'victimCharacterId' => $this->victimCharacterId,
            'victimCorporationId' => $this->victimCorporationId,
            'victimAllianceId' => $this->victimAllianceId,
            'victimShipTypeId' => $this->victimShipTypeId,
            'isLoss' => $this->isLoss,
            'isKill' => $this->isKill,
            'data' => $this->data,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data['id'] ?? null;
        $this->character = $data['character'] ?? null;
        $this->killmailId = $data['killmailId'] ?? null;
        $this->killmailHash = $data['killmailHash'] ?? null;
        $this->killmailTime = $data['killmailTime'] ?? null;
        $this->solarSystemId = $data['solarSystemId'] ?? null;
        $this->victimCharacterId = $data['victimCharacterId'] ?? null;
        $this->victimCorporationId = $data['victimCorporationId'] ?? null;
        $this->victimAllianceId = $data['victimAllianceId'] ?? null;
        $this->victimShipTypeId = $data['victimShipTypeId'] ?? null;
        $this->isLoss = $data['isLoss'] ?? false;
        $this->isKill = $data['isKill'] ?? false;
        $this->data = $data['data'] ?? [];
    }
}
