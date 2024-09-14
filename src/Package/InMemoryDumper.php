<?php

declare(strict_types=1);

namespace Packeton\Package;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectRepository;
use Packeton\Composer\MetadataFormat;
use Packeton\Entity\Group;
use Packeton\Entity\Package;
use Packeton\Entity\SubRepository;
use Packeton\Entity\Version;
use Packeton\Model\PacketonUserInterface as PUI;
use Packeton\Repository\PackageRepository;
use Packeton\Repository\VersionRepository;
use Packeton\Security\Acl\PackagesAclChecker;
use Packeton\Service\DistConfig;
use Packeton\Service\SubRepositoryHelper;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class InMemoryDumper
{
    private MetadataFormat $metadataFormat;
    private ?string $infoMessage;

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly PackagesAclChecker $checker,
        private readonly RouterInterface $router,
        private readonly SubRepositoryHelper $subRepositoryHelper,
        private readonly DistConfig $distConfig,
        ?array $config = null,
    ) {
        $this->infoMessage = $config['info_cmd_message'] ?? null;
        $this->metadataFormat = MetadataFormat::tryFrom((string) ($config['format'] ?? null)) ?: MetadataFormat::AUTO;
    }

    public function dump(?UserInterface $user = null, ?int $apiVersion = null, ?int $subRepo = null): array
    {
        return $this->dumpRootPackages($user, $apiVersion, $subRepo);
    }

    public function getFormat(): MetadataFormat
    {
        return $this->metadataFormat;
    }

    /**
     * @param null|UserInterface $user
     * @param string|Package $package
     * @param array $versionData
     *
     * @return array
     */
    public function dumpPackage(?UserInterface $user, $package, ?array $versionData = null): array
    {
        if (is_string($package)) {
            $package = $this->getPackageRepo()->findOneByName($package);
        }

        if (!$package instanceof Package || $package->isArchived()) {
            return [];
        }

        if ($user !== null && (!$user instanceof PUI || $this->checker->isGrantedAccessForPackage($user, $package) === false)) {
            return [];
        }

        $versionIds = $packageData = [];
        /** @var Version $version */
        foreach ($package->getVersions() as $version) {
            if ($user === null || $this->checker->isGrantedAccessForVersion($user, $version)) {
                $versionIds[$version->getId()] = $version;
            }
        }

        $versionRepo = $this->getVersionRepo();
        $versionData = $versionData === null ? $versionRepo->getVersionData(\array_keys($versionIds)) : $versionData;
        foreach ($versionIds as $version) {
            $data = $version->toArray($versionData);
            if (!$package->isSourceEnabled()) {
                unset($data['source']);
            }

            $packageData[$version->getVersion()] = \array_merge($data, ['uid' => $version->getId()]);
        }

        return $packageData;
    }

    private function dumpRootPackages(?UserInterface $user = null, ?int $apiVersion = null, ?int $subRepo = null)
    {
        /** @var SubRepository $subRepo */
        $subRepo = $subRepo ? $this->registry->getRepository(SubRepository::class)->find($subRepo) : null;
        [$providers, $packagesData, $availablePackages] = $this->dumpUserPackages($user, $apiVersion, $subRepo);

        $rootFile = ['packages' => []];
        $url = $this->router->generate('track_download', ['name' => 'VND/PKG']);
        $slug = $subRepo && !$this->subRepositoryHelper->isAutoHost() ? '/'. $subRepo->getSlug() : '';

        $rootFile['notify'] = str_replace('VND/PKG', '%package%', $url);
        $rootFile['notify-batch'] = $this->router->generate('track_download_batch');
        $rootFile['metadata-changes-url'] = $this->router->generate('metadata_changes');
        $rootFile['providers-url'] = $slug . '/p/%package%$%hash%.json';

        if ($this->distConfig->mirrorEnabled()) {
            $ref = '0000000000000000000000000000000000000000.zip';
            $zipball = $this->router->generate('download_dist_package', ['package' => 'VND/PKG', 'hash' => $ref]);
            $rootFile['mirrors'][] = ['dist-url' => \str_replace(['VND/PKG', $ref], ['%package%', '%reference%.%type%'], $zipball), 'preferred' => true];
        }

        $rootFile['metadata-url'] = $slug . '/p2/%package%.json';
        if ($subRepo) {
            $rootFile['_comment'] = "Subrepository {$subRepo->getSlug()}";
        }

        if (null !== $providers) {
            $userHash = \hash('sha256', \json_encode($providers));
            $rootFile['provider-includes'] = [
                'p/providers$%hash%.json' => [
                    'sha256' => $userHash
                ]
            ];
        }

        $rootFile['available-packages'] = $availablePackages;

        if ($this->metadataFormat->lazyProviders($apiVersion)) {
            unset($rootFile['provider-includes'], $rootFile['providers-url']);
            $rootFile['providers-lazy-url'] = $slug . '/p/%package%.json';
        }

        if (false === $this->metadataFormat->metadataUrl($apiVersion)) {
            unset($rootFile['metadata-url'], $rootFile['available-packages']);
        }

        if ($this->infoMessage) {
            $rootFile['info'] = $this->infoMessage;
        }

        return [$rootFile, $providers, $packagesData];
    }

    private function dumpUserPackages(?UserInterface $user = null, ?int $apiVersion = null, ?SubRepository $subRepo = null): array
    {
        if (false === $this->metadataFormat->providerIncludes($apiVersion)) {
            $allowed = $user ? $this->registry->getRepository(Group::class)
                ->getAllowedPackagesForUser($user, false) : null;

            $availablePackages = $this->getPackageRepo()->getPackageNames($allowed);
            $availablePackages = $subRepo ? $subRepo->filterAllowed($availablePackages) : $availablePackages;
            $availablePackages = $this->getPackageRepo()->filterByJson($availablePackages, static fn($data) => !($data['archived'] ?? false));

            return [null, [], $availablePackages];
        }

        $packages = $user ?
            $this->registry->getRepository(Group::class)
                ->getAllowedPackagesForUser($user) :
            $this->getPackageRepo()->findAll();

        $packages = array_values(array_filter($packages, static fn(Package $pkg) => !$pkg->isArchived()));

        $providers = $packagesData = [];
        $versionData = $this->getVersionData($packages);

        $availablePackages = array_map(static fn(Package $pkg) => $pkg->getName(), $packages);
        $availablePackages = $subRepo ? $subRepo->filterAllowed($availablePackages) : $availablePackages;
        $keys = array_flip($availablePackages);

        foreach ($packages as $package) {
            if (!isset($keys[$package->getName()]) || !($packageData = $this->dumpPackage($user, $package, $versionData))) {
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

        return [['providers' => $providers], $packagesData, $availablePackages];
    }


    private function getVersionData(array $packages)
    {
        $allPackagesIds = array_map(fn(Package $pkg) => $pkg->getId(), $packages);

        $repo = $this->getVersionRepo();

        $allVersionsIds = $repo
            ->createQueryBuilder('v')
            ->resetDQLPart('select')
            ->select('v.id')
            ->where('IDENTITY(v.package) IN (:ids)')
            ->setParameter('ids', $allPackagesIds)
            ->getQuery()
            ->getSingleColumnResult();

        return $repo->getVersionData($allVersionsIds);
    }

    /**
     * @return PackageRepository|ObjectRepository
     */
    private function getPackageRepo() : PackageRepository|ObjectRepository
    {
        return $this->registry->getRepository(Package::class);
    }

    /**
     * @return VersionRepository|ObjectRepository
     */
    private function getVersionRepo() : VersionRepository
    {
        return $this->registry->getRepository(Version::class);
    }
}
