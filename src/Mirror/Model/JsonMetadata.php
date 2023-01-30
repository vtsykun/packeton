<?php

declare(strict_types=1);

namespace Packeton\Mirror\Model;

class JsonMetadata
{
    use GZipTrait;

    public function __construct(
        private string $content,
        private ?int $unix = null,
        private ?string $hash = null,
        private array $options = [],
    ) {
        if (null === $this->unix) {
            $this->unix = \time();
        }
    }

    public function lastModified(): \DateTimeInterface
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))->setTimestamp($this->unix);
    }

    public function getContent(): string
    {
        return $this->decode($this->content);
    }

    public function decodeJson(): array
    {
        $data = \json_decode($this->getContent(), true);
        return \is_array($data) ? $data : [];
    }

    public function hash(): ?string
    {
        return $this->hash;
    }

    public function getOption(): MetadataOptions
    {
        return new MetadataOptions($this->options);
    }

    public function withContent(string|array|callable $content): self
    {
        if (\is_callable($content)) {
            $content = $content($this->decodeJson());
        }

        $content = \is_array($content) ? \json_encode($content, JSON_UNESCAPED_SLASHES) : $content;

        $clone = clone $this;
        $clone->content = $content;
        $clone->hash = null;

        return $clone;
    }
}
