<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Package;

use Doctrine\Persistence\ManagerRegistry;
use Packagist\WebBundle\Entity\Group;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\User;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Security\Acl\PackagesAclChecker;
use Symfony\Component\Routing\RouterInterface;

class InMemoryDumper
{
    private $registry;
    private $checker;
    private $router;

    public function __construct(ManagerRegistry $registry, PackagesAclChecker $checker, RouterInterface $router)
    {
        $this->router = $router;
        $this->registry = $registry;
        $this->checker = $checker;
    }

    /**
     * @param User|null $user
     * @return array
     */
    public function dump(User $user = null): array
    {
        return $this->dumpRootPackages($user);
    }

    /**
     * @param null|User $user
     * @param string|Package $package
     *
     * @return array
     */
    public function dumpPackage(?User $user, $package): array
    {
        if (is_string($package)) {
            $package = $this->registry
                ->getRepository(Package::class)
                ->findOneBy(['name' => $package]);
        }

        if (!$package instanceof Package) {
            return [];
        }

        if ($user !== null && $this->checker->isGrantedAccessForPackage($user, $package) === false) {
            return [];
        }

        $versionIds = $packageData = [];
        /** @var Version $version */
        foreach ($package->getVersions() as $version) {
            if ($user === null || $this->checker->isGrantedAccessForVersion($user, $version)) {
                $versionIds[$version->getId()] = $version;
            }
        }

        $versionRepo = $this->registry->getRepository(Version::class);
        $versionData = $versionRepo->getVersionData(\array_keys($versionIds));
        foreach ($versionIds as $version) {
            $packageData[$version->getVersion()] = \array_merge(
                $version->toArray($versionData),
                ['uid' => $version->getId()]
            );
        }

        return $packageData;
    }

    private function dumpRootPackages(User $user = null)
    {
        list($providers, $packagesData) = $this->dumpUserPackages($user);

        $rootFile = ['packages' => []];
        $url = $this->router->generate('track_download', ['name' => 'VND/PKG']);
        $rootFile['notify'] = str_replace('VND/PKG', '%package%', $url);
        $rootFile['notify-batch'] = $this->router->generate('track_download_batch');
        $rootFile['providers-url'] = '/p/%package%$%hash%.json';

        $userHash = \hash('sha256', \json_encode($providers));
        $rootFile['provider-includes'] = [
            'p/providers$%hash%.json' => [
                'sha256' => $userHash
            ]
        ];

        return [$rootFile, $providers, $packagesData];
    }

    private function dumpUserPackages(User $user = null): array
    {
        $packages = $user ?
            $this->registry->getRepository(Group::class)
                ->getAllowedPackagesForUser($user) :
            $this->registry->getRepository(Package::class)->findAll();

        $providers = $packagesData = $packagesData = [];
        foreach ($packages as $package) {
            if (!$packageData = $this->dumpPackage($user, $package)) {
                continue;
            }

            $packageData = [
                'packages' => [$package->getName() => $packageData]
            ];
            $packagesData[$package->getName()] = $packageData;
            $providers[$package->getName()] = [
                'sha256' => \hash('sha256', \json_encode($packageData))
            ];
        }

        return [['providers' => $providers], $packagesData];
    }
}
