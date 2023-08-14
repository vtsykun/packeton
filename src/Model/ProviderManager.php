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

    public function setSecurityAdvisory(string $id, array $advisory)
    {
        unset($advisory['version']);
        $this->hSet("security-advisory", $id, json_encode($advisory, \JSON_UNESCAPED_SLASHES));
    }

    public function getSecurityAdvisory(string $id): ?array
    {
        $advisory = $this->redis->hGet("security-advisory", $id);
        return $advisory ? json_decode($advisory, true) : null;
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
                $this->hSet('packages-lm-all', $key, $unix);
            }
        } catch (\Exception) {
        }
    }

    public function getLastModify(string $package, bool $isDev = null): \DateTimeInterface
    {
        $key = $isDev && !str_ends_with($package, '~dev') ? $package.'~dev' : $package;

        $unix = $this->redis->hGet('packages-lm-all', $key);
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
        $keys = ['lm:'.$package->getName(), 'lm:'.$package->getName().'~dev'];
        $this->redis->del($keys[0]);
        $this->redis->del($keys[1]);

        $this->redis->hDel('packages-lm-all', $keys[0]);
        $this->redis->hDel('packages-lm-all', $keys[1]);
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

    private function hSet(string $key, string $hashKey, mixed $value)
    {
        if (($result = $this->redis->hSet($key, $hashKey, $value)) === false) {
            if (false !== $this->redis->get($key)) {
                $this->redis->del($key);
                $result = $this->redis->hSet($key, $hashKey, $value);
            }
        }

        $result;
    }
}
