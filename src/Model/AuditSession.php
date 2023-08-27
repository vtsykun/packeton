<?php

declare(strict_types=1);

namespace Packeton\Model;

class AuditSession
{
    public function __construct(private readonly array $session)
    {
    }

    public function getIp(): ?string
    {
        return $this->session['ip'] ?? null;
    }

    public function isSuccess(): bool
    {
        if ($this->session['error'] ?? null) {
            return false;
        }

        return true;
    }

    public function getUserAgent(): ?string
    {
        return $this->session['ua'] ?? null;
    }

    public function getUserIdentity(): ?string
    {
        return $this->session['uid'] ?? null;
    }

    public function getUsage(): int
    {
        return (int)($this->session['usage'] ?? 1);
    }

    public function getLastUsage(): \DateTimeImmutable
    {
        $time = $this->session['last_usage'] ?? time();

        return new \DateTimeImmutable('@'.$time);
    }

    public function getInfo(): ?string
    {
        $info = [];
        if ($this->session['ua'] ?? null) {
            $info[] = "User-Agent: " . $this->session['ua'];
        }

        if ($this->session['ip'] ?? null) {
            $info[] = "IP: " . $this->session['ip'];
        }

        if ($this->session['api_token'] ?? null) {
            $info[] = "Api token: " . $this->session['api_token'];
        }

        if ($this->session['error'] ?? null) {
            $info[] = "Error: " . $this->session['error'];
        }

        if ($this->session['downloads'] ?? null) {
            $info[] = "Downloads info: " . $this->session['downloads'];
        }

        return implode("\n", $info);
    }

    public function getSource(): string
    {
        return match (true) {
            ($this->session['remember_me'] ?? false) => 'Remember me',
            ($this->session['web'] ?? false) => 'New Session',
            null !== ($this->session['api_token'] ?? null) => 'Api Token',
            null !== ($this->session['downloads'] ?? null) => 'Downloads',
            default => 'Unknown',
        };
    }

    public static function create(array $session): self
    {
        return new AuditSession($session);
    }
}
