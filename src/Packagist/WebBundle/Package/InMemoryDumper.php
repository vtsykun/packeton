<?php

namespace Packagist\WebBundle\Package;

use Doctrine\Common\Persistence\ManagerRegistry;
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

    public function dump(User $user = null)
    {
        return $this->dumpRootPackages($user);
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
            '/p/providers$%hash%.json' => [
                'sha256' => $userHash
            ]
        ];

        return [$rootFile, $providers, $packagesData];
    }

    private function dumpUserPackages(User $user = null)
    {
        $packages = $user ? $this->registry->getRepository('PackagistWebBundle:Group')->getAllowedPackagesForUser($user) :
            $this->registry->getRepository('PackagistWebBundle:Package')->findAll();

        $versionRepo = $this->registry->getRepository('PackagistWebBundle:Version');

        $providers = [];
        $packagesData = [];
        foreach ($packages as $package) {
            if ($user !== null && $this->checker->isGrantedAccessForPackage($user, $package) === false) {
                continue;
            }

            /** @var Version[] $versionIds */
            $versionIds = [];
            $packageData = [];
            foreach ($package->getVersions() as $version) {
                if ($user === null || $this->checker->isGrantedAccessForVersion($user, $version)) {
                    $versionIds[$version->getId()] = $version;
                }
            }

            $versionData = $versionRepo->getVersionData(\array_keys($versionIds));
            foreach ($versionIds as $version) {
                $packageData[$version->getVersion()] = $version->toArray($versionData);
            }

            $packageData = [
                'packages' => [
                    $package->getName() => $packageData
                ]
            ];
            $packagesData[$package->getName()] = $packageData;
            $providers[$package->getName()] = [
                'sha256' => \hash('sha256', \json_encode($packageData))
            ];
        }

        return [$providers, $packagesData];
    }
}
