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

namespace Packagist\WebBundle\Model;

use Doctrine\Persistence\ManagerRegistry;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Repository\PackageRepository;
use Predis\Client;

class ProviderManager
{
    protected $redis;
    protected $registry;
    protected $initializedProviders = false;

    public function __construct(Client $redis, ManagerRegistry $registry)
    {
        $this->redis = $redis;
        $this->registry = $registry;
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

    public function getPackageNames()
    {
        if (!$this->redis->scard('set:packages')) {
            $names = $this->getRepo()->getPackageNames();
            while ($names) {
                $nameSlice = array_splice($names, 0, 1000);
                $this->redis->sadd('set:packages', $nameSlice);
            }
        }

        $names = $this->redis->smembers('set:packages');
        sort($names, SORT_STRING);

        return $names;
    }

    public function insertPackage(Package $package)
    {
        $this->redis->sadd('set:packages', strtolower($package->getName()));
    }

    public function deletePackage(Package $package)
    {
        $this->redis->srem('set:packages', strtolower($package->getName()));
    }

    private function populateProviders()
    {
        $names = $this->getRepo()->getProvidedNames();
        while ($names) {
            $nameSlice = array_splice($names, 0, 1000);
            $this->redis->sadd('set:providers', $nameSlice);
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
