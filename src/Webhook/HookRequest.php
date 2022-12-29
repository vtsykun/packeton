<?php

declare(strict_types=1);

namespace Packeton\Webhook;

class HookRequest implements \JsonSerializable
{
    private $method;
    private $options;
    private $url;
    private $body;

    public function __construct(string $url, string $method, array $options = [], $body = null)
    {
        $this->url =  $url;
        $this->method = $method;
        $this->options = $options;
        $this->body = $body;
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
    public function getBody()
    {
        return $this->body;
    }

    /**
     * @return array
     */
    public function getHeaders(): array
    {
        $headers = $this->options['headers'] ?? [];
        $headers = is_array($headers) ? $headers : [];

        return array_filter($headers, function ($v) {
            return is_scalar($v);
        });
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
