<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_setting')]
class AppSetting
{
    #[ORM\Id]
    #[ORM\Column(name: 'setting_key', type: 'string', length: 100)]
    private string $key;

    #[ORM\Column(name: 'setting_value', type: 'text', nullable: true)]
    private ?string $value = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $key, ?string $value = null)
    {
        $this->key = $key;
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): static
    {
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
