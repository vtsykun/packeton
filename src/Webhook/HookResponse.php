<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Webhook;

class HookResponse implements \JsonSerializable
{
    private $request;
    private $responseBody;
    private $options;
    private $logs = [];

    public function __construct(HookRequest $request, $responseBody = null, array $options = [])
    {
        $this->logs = isset($options['logs']) && is_array($options['logs']) ? $options['logs'] : [];
        unset($options['logs']);

        $this->request = $request;
        $this->responseBody = $responseBody;
        $this->options = $options;
    }

    /**
     * @return HookRequest
     */
    public function getRequest(): HookRequest
    {
        return $this->request;
    }

    /**
     * @return null|string
     */
    public function getResponseBody()
    {
        return $this->responseBody;
    }

    /**
     * @return array
     */
    public function toArrayResponse(): array
    {
        $result = $this->responseBody ? @json_decode($this->responseBody, true) : null;
        return is_array($result) ? $result : [];
    }

    /**
     * @return int
     */
    public function getStatusCode(): int
    {
        return (int) ($this->options['status_code'] ?? 0);
    }

    /**
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->getStatusCode() >= 200 && $this->getStatusCode() < 300;
    }

    /**
     * @return int|null
     */
    public function getTotalTime()
    {
        return $this->options['total_time'] ?? null;
    }

    /**
     * @return array
     */
    public function getLogs()
    {
        return $this->logs;
    }

    /**
     * @param array $logs
     */
    public function setLogs(array $logs = []): void
    {
        $this->logs = $logs;
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
     * @return array
     */
    public function getRequestHeaders()
    {
        if (isset($this->options['request_headers'])) {
            $headers = $this->options['request_headers'] ?? [];
            $headers = is_array($headers) ? $headers : [];

            return array_filter($headers, function ($v) {
                return is_scalar($v);
            });
        }

        return $this->request->getHeaders();
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize()
    {
        $options = $this->options;
        $options['logs'] = $this->logs;

        return [
            'request' => $this->request,
            'response' => $this->responseBody,
            'options' => $options,
            'logs' => $this->logs,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            HookRequest::fromArray($data['request']),
            $data['response'] ?? null,
            $data['options'] ?? []
        );
    }
}
