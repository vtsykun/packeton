<?php

namespace Packeton\Security\Api;

use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;
use Symfony\Component\Security\Core\User\UserInterface;

class ApiUsernamePasswordToken extends AbstractToken
{
    private $providerKey;

    /**
     * @param UserInterface            $user        The username (like a nickname, email address, etc.), or a UserInterface instance or an object implementing a __toString method
     * @param string                   $providerKey The provider key
     * @param string[] $roles       An array of roles
     *
     * @throws \InvalidArgumentException
     */
    public function __construct($user, $providerKey, array $roles = [])
    {
        parent::__construct($roles);

        if (empty($providerKey)) {
            throw new \InvalidArgumentException('$providerKey must not be empty.');
        }

        $this->setUser($user);
        $this->providerKey = $providerKey;

        parent::setAuthenticated(count($roles) > 0, false);
    }

    /**
     * {@inheritdoc}
     */
    public function getCredentials()
    {
        return '';
    }

    /**
     * Returns the provider key.
     *
     * @return string The provider key
     */
    public function getProviderKey()
    {
        return $this->providerKey;
    }

    /**
     * {@inheritdoc}
     */
    public function __serialize(): array
    {
        return [$this->providerKey, parent::__serialize()];
    }

    /**
     * {@inheritdoc}
     */
    public function __unserialize(array $serialized): void
    {
        [$this->providerKey, $parentStr] = $serialized;
        parent::__unserialize($parentStr);
    }
}
