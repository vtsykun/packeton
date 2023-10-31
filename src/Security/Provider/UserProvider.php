<?php

declare(strict_types=1);

namespace Packeton\Security\Provider;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\User;
use Packeton\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(protected ManagerRegistry $registry)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->getRepo()->findOneByUsernameOrEmail($identifier);
        if (null === $user) {
            throw new UserNotFoundException('User not found');
        }

        return $user;
    }

    /**
     * {@inheritDoc}
     */
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException('Expected '.User::class.', got '.get_class($user));
        }

        $uow = $this->getEM()->getUnitOfWork();
        $needRefresh = $uow->containsIdHash((string)$user->getId(), User::class);
        if (!$user = $this->getRepo()->find($user->getId())) {
            throw new UserNotFoundException('User not found');
        }

        if ($needRefresh) {
            $this->getEM()->refresh($user);
        }

        return $user;
    }

    /**
     * {@inheritDoc}
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new \UnexpectedValueException('Expected '.User::class.', got '.get_class($user));
        }

        $user->setPassword($newHashedPassword);
        $user->setSalt(null);

        $em = $this->registry->getManager();
        $em->persist($user);
        $em->flush();
    }

    /**
     * {@inheritDoc}
     */
    public function supportsClass($class): bool
    {
        return User::class === $class;
    }

    private function getRepo(): UserRepository
    {
        return $this->getEM()->getRepository(User::class);
    }

    private function getEM(): EntityManagerInterface
    {
        return $this->registry->getManager();
    }
}
