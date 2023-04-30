<?php

declare(strict_types=1);

namespace Packeton\Security\Token;

class TokenCheckerRegistry
{
    /** @var array|TokenCheckerInterface[]  */
    private $optionalCheckers = [];

    public function __construct(private readonly TokenCheckerInterface $default)
    {
    }

    public function addTokenChecker(TokenCheckerInterface $checker): void
    {
        $this->optionalCheckers[] = $checker;
    }

    public function getTokenChecker(string $username, string $token): TokenCheckerInterface
    {
        foreach ($this->optionalCheckers as $checker) {
            if ($checker->support($username, $token)) {
                return $checker;
            }
        }

        return $this->default;
    }
}
