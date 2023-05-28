<?php

declare(strict_types=1);

namespace Packeton\Webhook;

class HookRequest implements \JsonSerializable
{
    public function __construct(
        protected string $url,
        protected string $method,
        protected array $options = [],
        protected mixed $body = null
    ) {
    }

    /**
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }


    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    /**
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return null|mixed
     */
    public function getBody(): mixed
    {
        return $this->body;
    }

    public function getHeaders(array $options = []): array
    {
        $headers = $options ? ($options['headers'] ?? []) : ($this->options['headers'] ?? []);
        $headers = is_array($headers) ? $headers : [];

        return array_filter($headers, fn ($v) => is_scalar($v));
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'body' => $this->body,
            'options' => $this->options
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['url'], $data['method'], $data['options'] ?? [], $data['body'] ?? null);
    }
}
