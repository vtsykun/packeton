<?php

declare(strict_types=1);

namespace Packeton\Security\Acl;

class ObjectIdentity
{
    public function __construct(private readonly string|int $identifier, private readonly string $type)
    {
    }

    /**
     * @return int|string
     */
    public function getIdentifier(): int|string
    {
        return $this->identifier;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }
}
