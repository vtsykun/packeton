<?php

namespace Packeton\Security\Api;

use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;

class ApiUsernamePasswordToken extends AbstractToken
{
    private $credentials;
    private $providerKey;

    /**
     * @param string|object            $user        The username (like a nickname, email address, etc.), or a UserInterface instance or an object implementing a __toString method
     * @param mixed                    $credentials This usually is the password of the user
     * @param string                   $providerKey The provider key
     * @param string[] $roles       An array of roles
     *
     * @throws \InvalidArgumentException
     */
    public function __construct($user, $credentials, $providerKey, array $roles = [])
    {
        parent::__construct($roles);

        if (empty($providerKey)) {
            throw new \InvalidArgumentException('$providerKey must not be empty.');
        }

        $this->setUser($user);
        $this->credentials = $credentials;
        $this->providerKey = $providerKey;

        parent::setAuthenticated(count($roles) > 0);
    }

    /**
     * {@inheritdoc}
     */
    public function getCredentials()
    {
        return $this->credentials;
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
    public function eraseCredentials()
    {
        parent::eraseCredentials();

        $this->credentials = null;
    }

    /**
     * {@inheritdoc}
     */
    public function __serialize(): array
    {
        return [$this->credentials, $this->providerKey, parent::__serialize()];
    }

    /**
     * {@inheritdoc}
     */
    public function __unserialize(array $serialized): void
    {
        list($this->credentials, $this->providerKey, $parentStr) = $serialized;
        parent::__unserialize($parentStr);
    }
}
