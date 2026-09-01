<?php

namespace App\Service\Discord\Model;

class DiscordMessage
{
    private ?string $content = null;
    private ?string $username = 'Keepers of Duat';
    private ?string $avatarUrl = null;
    /** @var DiscordEmbed[] */
    private array $embeds = [];

    public static function create(?string $content = null): self
    {
        $message = new self();
        if ($content !== null) {
            $message->setContent($content);
        }
        return $message;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): self
    {
        $this->username = $username;
        return $this;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function setAvatarUrl(?string $avatarUrl): self
    {
        $this->avatarUrl = $avatarUrl;
        return $this;
    }

    public function addEmbed(DiscordEmbed $embed): self
    {
        $this->embeds[] = $embed;
        return $this;
    }

    /**
     * @return DiscordEmbed[]
     */
    public function getEmbeds(): array
    {
        return $this->embeds;
    }

    public function toArray(): array
    {
        $data = [];

        if ($this->content !== null) {
            $data['content'] = mb_substr($this->content, 0, 2000);
        }
        if ($this->username !== null) {
            $data['username'] = $this->username;
        }
        if ($this->avatarUrl !== null) {
            $data['avatar_url'] = $this->avatarUrl;
        }
        if (!empty($this->embeds)) {
            $data['embeds'] = array_map(
                fn(DiscordEmbed $embed) => $embed->toArray(),
                array_slice($this->embeds, 0, 10)
            );
        }

        return $data;
    }
}
