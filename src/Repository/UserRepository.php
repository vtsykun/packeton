<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packeton\Repository;

use Doctrine\ORM\EntityRepository;
use Packeton\Entity\Group;
use Packeton\Entity\Package;
use Packeton\Entity\User;
use Packeton\Integrations\LoginInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class UserRepository extends EntityRepository
{
    public function findUsersMissingApiToken()
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.apiToken IS NULL');
        return $qb->getQuery()->getResult();
    }

    public function findByOAuth2Data(array $data): ?User
    {
        $user = null;
        if ($identifier = ($data['user_identifier'] ?? null)) {
            $user = ($data['_type'] ?? null) === LoginInterface::LOGIN_USERNAME ?
                 $this->findOneBy(['usernameCanonical' => mb_strtolower($identifier)])
                 : $this->findOneBy(['emailCanonical' => $identifier]);
        }

        if (null === $user && isset($data['external_id'])) {
            $user = $this->findOneBy(['githubId' => $data['external_id']]);
        }

        return $user;
    }

    public function findOneByUsernameOrEmail(string $usernameOrEmail)
    {
        if (preg_match('/^.+@\S+\.\S+$/', $usernameOrEmail)) {
            $user = $this->findOneBy(['emailCanonical' => $usernameOrEmail]);
            if (null !== $user) {
                return $user;
            }
        }

        return $this->findOneBy(['usernameCanonical' => mb_strtolower($usernameOrEmail)]);
    }

    public function getPackageMaintainersQueryBuilder(Package $package, User $excludeUser=null)
    {
        $qb = $this->createQueryBuilder('u')
            ->select('u')
            ->innerJoin('u.packages', 'p', 'WITH', 'p.id = :packageId')
            ->setParameter(':packageId', $package->getId())
            ->orderBy('u.username', 'ASC');

        if ($excludeUser) {
            $qb->andWhere('u.id <> :userId')
                ->setParameter(':userId', $excludeUser->getId());
        }

        return $qb;
    }

    public function getApiData(User $user): array
    {
        $repo =  $this->getEntityManager()->getRepository(Group::class);

        return [
            'id' => $user->getId(),
            'username' => $user->getUserIdentifier(),
            'email' => $user->getEmailCanonical(),
            'enabled' => $user->isEnabled(),
            'createdAt' => $user->getCreatedAt(),
            'expireAt' => $user->getExpiresAt(),
            'expiredUpdatesAt' => $user->getExpiredUpdatesAt(),
            'apiToken' => $user->getApiToken(),
            'roles' => $user->getRoles(),
            'groups' => $user->getGroups()->map(fn($g) => $g->getId())->toArray(),
            'allowedPackages' => $repo->getAllowedPackagesForUser($user, 1),
            'allowedProxies' => $repo->getAllowedProxies($user),
            'isMaintainer' => $user->isMaintainer(),
            'gravatarUrl' => $user->getGravatarUrl(),
            'fullAccess' => $user->isMaintainer() || $user->hasRole('ROLE_FULL_CUSTOMER')
        ];
    }
}
