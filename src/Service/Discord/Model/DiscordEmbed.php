<?php

namespace App\Service\Discord\Model;

class DiscordEmbed
{
    private ?string $title = null;
    private ?string $description = null;
    private ?string $url = null;
    private ?int $color = null;
    private ?\DateTimeInterface $timestamp = null;
    private ?array $footer = null;
    private ?array $thumbnail = null;
    private ?array $image = null;
    private ?array $author = null;
    private array $fields = [];

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): self
    {
        $this->url = $url;
        return $this;
    }

    public function getColor(): ?int
    {
        return $this->color;
    }

    public function setColor(?int $color): self
    {
        $this->color = $color;
        return $this;
    }

    public function getTimestamp(): ?\DateTimeInterface
    {
        return $this->timestamp;
    }

    public function setTimestamp(?\DateTimeInterface $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    public function setFooter(string $text, ?string $iconUrl = null): self
    {
        $this->footer = [
            'text' => $text,
            'icon_url' => $iconUrl,
        ];
        return $this;
    }

    public function setThumbnail(string $url): self
    {
        $this->thumbnail = ['url' => $url];
        return $this;
    }

    public function setImage(string $url): self
    {
        $this->image = ['url' => $url];
        return $this;
    }

    public function setAuthor(string $name, ?string $url = null, ?string $iconUrl = null): self
    {
        $this->author = [
            'name' => $name,
            'url' => $url,
            'icon_url' => $iconUrl,
        ];
        return $this;
    }

    public function addField(string $name, string $value, bool $inline = false): self
    {
        $this->fields[] = [
            'name' => $name,
            'value' => $value,
            'inline' => $inline,
        ];
        return $this;
    }

    public function toArray(): array
    {
        $data = [];

        if ($this->title !== null) {
            $data['title'] = mb_substr($this->title, 0, 256);
        }
        if ($this->description !== null) {
            $data['description'] = mb_substr($this->description, 0, 4096);
        }
        if ($this->url !== null) {
            $data['url'] = $this->url;
        }
        if ($this->color !== null) {
            $data['color'] = $this->color;
        }
        if ($this->timestamp !== null) {
            $data['timestamp'] = $this->timestamp->format(\DateTimeInterface::ATOM);
        }
        if ($this->footer !== null) {
            $data['footer'] = $this->footer;
        }
        if ($this->thumbnail !== null) {
            $data['thumbnail'] = $this->thumbnail;
        }
        if ($this->image !== null) {
            $data['image'] = $this->image;
        }
        if ($this->author !== null) {
            $data['author'] = $this->author;
        }
        if (!empty($this->fields)) {
            $data['fields'] = array_map(function ($field) {
                return [
                    'name' => mb_substr((string)$field['name'], 0, 256),
                    'value' => mb_substr((string)$field['value'], 0, 1024),
                    'inline' => (bool)$field['inline'],
                ];
            }, array_slice($this->fields, 0, 25));
        }

        return $data;
    }
}
