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

namespace Packeton\Model;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Package;
use Packeton\Entity\User;
use Packeton\Repository\UserRepository;
use Packeton\Service\SubRepositoryHelper;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class FavoriteManager
{
    public function __construct(
        protected \Redis $redis,
        protected ManagerRegistry $registry,
        protected SubRepositoryHelper $subRepositoryHelper,
    ) {
    }

    public function markFavorite(UserInterface $user, Package $package)
    {
        if (!$this->isMarked($user, $package)) {
            $this->redis->zadd('pkg:'.$package->getId().':fav', time(), $this->getUid($user));
            $this->redis->zadd('usr:'.$this->getUid($user).':fav', time(), $package->getId());
        }
    }

    public function removeFavorite(UserInterface $user, Package $package)
    {
        $this->redis->zrem('pkg:'.$package->getId().':fav', $this->getUid($user));
        $this->redis->zrem('usr:'.$this->getUid($user).':fav', $package->getId());
    }

    public function getFavorites(UserInterface $user, $limit = 0, $offset = 0)
    {
        $favoriteIds = $this->redis->zrevrange('usr:'.$this->getUid($user).':fav', $offset, $offset + $limit - 1);
        $favoriteIds = $this->subRepositoryHelper->allowedPackageIds($favoriteIds);

        return $this->getPackageRepo()->findById($favoriteIds);
    }

    public function getFavoriteCount(UserInterface $user)
    {
        return $this->redis->zcard('usr:'. $this->getUid($user) .':fav');
    }

    public function getFavers(Package $package, $offset = 0, $limit = 100)
    {
        $faverIds = $this->redis->zrevrange('pkg:'.$package->getId().':fav', $offset, $offset + $limit - 1);

        return $this->getUserRepo()->findById($faverIds);
    }

    public function getFaverCount(Package $package)
    {
        return $this->redis->zcard('pkg:'.$package->getId().':fav') + $package->getGitHubStars();
    }

    public function getFaverCounts(array $packageIds)
    {
        $res = [];

        // TODO should be done with scripting when available
        foreach ($packageIds as $id) {
            if (ctype_digit((string) $id)) {
                $res[$id] = $this->redis->zcard('pkg:'.$id.':fav');
            }
        }

        $rows = $this->getPackageRepo()->getGitHubStars($packageIds);
        foreach ($rows as $row) {
            $res[$row['id']] += $row['gitHubStars'];
        }

        return $res;
    }

    public function isMarked(UserInterface $user, Package $package)
    {
        return false !== $this->redis->zrank('usr:'.$this->getUid($user).':fav', $package->getId());
    }

    /**
     * @return \Doctrine\ORM\EntityRepository|\Doctrine\Persistence\ObjectRepository|UserRepository|object
     */
    private function getUserRepo()
    {
        return $this->registry->getRepository(User::class);
    }

    /**
     * @return \Doctrine\ORM\EntityRepository|\Doctrine\Persistence\ObjectRepository|\Packeton\Repository\PackageRepository
     */
    private function getPackageRepo()
    {
        return $this->registry->getRepository(Package::class);
    }

    private function getUid(UserInterface $user): string
    {
        if ($user instanceof User) {
            return (string) $user->getId();
        }

        return sha1($user->getUserIdentifier());
    }
}
