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
use Packeton\Repository\PackageRepository;

class ProviderManager
{
    public const DEV_UPDATED = 1;
    public const STAB_UPDATED = 2;

    protected $initializedProviders = false;

    protected $initializedPackages = [];
    protected $initializedPackagesUnix = false;

    public function __construct(
        protected \Redis $redis,
        protected ManagerRegistry $registry
    ) {
    }

    public function packageExists($name)
    {
        return (bool) $this->redis->sismember('set:packages', strtolower($name));
    }

    public function packageIsProvided($name)
    {
        if (false === $this->initializedProviders) {
            if (!$this->redis->scard('set:providers')) {
                $this->populateProviders();
            }
            $this->initializedProviders = true;
        }

        return (bool) $this->redis->sismember('set:providers', strtolower($name));
    }

    public function getPackageNames(bool $reload = false): array
    {
        $lastModify = $this->getRootLastModify()->getTimestamp();
        if (false === $reload && $this->initializedPackagesUnix === $lastModify) {
            return $this->initializedPackages;
        }

        if (true === $reload) {
            $names = $this->getRepo()->getPackageNames();
            $this->redis->del('set:packages');
            while ($names) {
                $nameSlice = array_splice($names, 0, 1000);
                $this->redis->sAddArray('set:packages', $nameSlice);
            }
        }

        $names = $this->redis->sMembers('set:packages');
        sort($names, SORT_STRING);
        $this->initializedPackagesUnix = $lastModify;

        return $this->initializedPackages = $names;
    }

    public function setRootLastModify(int $unix = null): void
    {
        try {
            $this->redis->set('packages-last-modify', $unix ?: time());
        } catch (\Exception) {}
    }

    public function getRootLastModify(): \DateTimeInterface
    {
        $unix = $this->redis->get('packages-last-modify');
        if (empty($unix) || !is_numeric($unix)) {
            $this->setRootLastModify($unix = time());
        }

        return \DateTime::createFromFormat('U', (int)$unix);
    }

    public function setLastModify(string $package, int $flags = null, int $unix = null): void
    {
        try {
            $flags ??= 3;
            $unix ??= time();
            $keys = [];
            if ($flags & self::DEV_UPDATED) {
                $keys[] = $package . '~dev';
            }
            if ($flags & self::STAB_UPDATED) {
                $keys[] = $package;
            }

            foreach ($keys as $key) {
                $this->redis->set('lm:'.$key, $unix);
            }
        } catch (\Exception) {}
    }

    public function getLastModify(string $package, bool $isDev = null): \DateTimeInterface
    {
        $key = match ($isDev) {
            $isDev === true => $package . '~dev',
            default => $package,
        };

        $key = 'lm:'.$key;
        $unix = $this->redis->get($key);
        if (empty($unix) || !is_numeric($unix)) {
            if (!$this->packageExists(preg_replace('/~dev$/', '', $package))) {
                $unix = time();
            } else {
                $this->setLastModify($package, null, $unix = time());
            }
        }

        return \DateTime::createFromFormat('U', (int)$unix);
    }

    public function insertPackage(Package $package)
    {
        $this->redis->sadd('set:packages', strtolower($package->getName()));
    }

    public function deletePackage(Package $package)
    {
        $this->redis->srem('set:packages', strtolower($package->getName()));
        $this->redis->del('lm:'.$package->getName());
        $this->redis->del('lm:'.$package->getName().'~dev');
    }

    private function populateProviders()
    {
        $names = $this->getRepo()->getProvidedNames();
        while ($names) {
            $nameSlice = array_splice($names, 0, 1000);
            $this->redis->sAddArray('set:providers', $nameSlice);
        }

        $this->redis->expire('set:providers', 3600);
    }

    /**
     * @return \Doctrine\ORM\EntityRepository|\Doctrine\Persistence\ObjectRepository|object|PackageRepository
     */
    private function getRepo()
    {
        return $this->registry->getRepository(Package::class);
    }
}
