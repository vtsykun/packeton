<?php

declare(strict_types=1);

namespace Packeton\Service;

use Composer\IO\NullIO;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\PackageInterface;
use Doctrine\Persistence\ManagerRegistry;
use League\Flysystem\FilesystemOperator;
use Packeton\Composer\PackagistFactory;
use Packeton\Composer\Repository\PacketonRepositoryInterface;
use Packeton\Entity\Package;
use Packeton\Entity\Version;
use Packeton\Integrations\IntegrationRegistry;
use Packeton\Integrations\ZipballInterface;
use Packeton\Model\UploadZipballStorage;
use Packeton\Package\RepTypes;
use Symfony\Component\Filesystem\Filesystem;

class DistManager
{
    public const EMPTY_VERSION_NAME = 'dev-master';

    public function __construct(
        private readonly DistConfig $config,
        private readonly PackagistFactory $packagistFactory,
        private readonly ManagerRegistry $registry,
        private readonly UploadZipballStorage $artifact,
        private readonly IntegrationRegistry $integrations,
        private readonly FilesystemOperator $baseStorage,
        private readonly Filesystem $fs,
    ) {
    }

    public function getDist(string $reference, Package $package): mixed
    {
        $version = $package->getVersionByReference($reference);
        $keyName = $this->config->buildName($package->getName(), $reference, $version?->getVersion() ?: self::EMPTY_VERSION_NAME);
        $cachedName = $this->config->resolvePath($keyName);

        if (($useCached = $this->fs->exists($cachedName)) || $this->baseStorage->fileExists($keyName)) {
            return $this->loadCacheOrArchiveFromStorage($keyName, $useCached);
        }

        return $this->buildAndWriteArchive($reference, $package, $version);
    }

    public function buildAndWriteArchive(string $reference, Package $package, Version|string $version = null): mixed
    {
        $versionName = $version instanceof Version ? $version->getVersion() : $version;

        $keyName = $this->config->buildName($package->getName(), $reference, $versionName ?: self::EMPTY_VERSION_NAME);
        $archive = $this->buildArchive($reference, $package, $version);

        if (is_string($archive) && $this->fs->exists($archive) && !$this->baseStorage->fileExists($keyName)) {
            $stream = fopen($archive, 'r');
            $this->baseStorage->writeStream($keyName, $stream);
        }

        return $archive;
    }

    public function buildArchive(string $reference, Package $package, Version|string $version = null): mixed
    {
        $version ??= $package->getVersionByReference($reference);
        $versionName = $version instanceof Version ? $version->getVersion() : $version;

        return match (true) {
            $package->getRepoType() === RepTypes::INTEGRATION => $this->downloadUsingIntegration($reference, $package, $versionName),
            RepTypes::isBuildInDist($package->getRepoType()) => $this->downloadArtifact($reference, $package),
            default => $this->downloadVCS($reference, $package, $versionName)
        };
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnable();
    }

    public function downloadUsingIntegration(string $reference, Package $package, ?string $versionName = null): string
    {
        if (!$oauth = $package->getIntegration()) {
            throw new \InvalidArgumentException('Oauth2 credentials package->integration can not be empty for integration VCS package type');
        }

        $versionName ??= self::EMPTY_VERSION_NAME;

        $repository = $this->createRepositoryAndIo($package);
        /** @var  $archiveManager */
        $archiveManager = $this->packagistFactory->createArchiveManager($repository->getIO(), $repository);
        $archiveManager->setOverwriteFiles(false);
        $archiveManager->getDownloadManager()->setPreferDist(true);

        $targetPath = $this->config->generateDistFileName($package->getName(), $reference, $versionName);
        $targetDir = $this->config->generateTargetDir($package->getName());
        $fileName = $this->config->getFileName($reference, $versionName);
        $format = $this->config->getArchiveFormat();

        try {
            if ($path = $archiveManager->tryFromGitArchive($reference, $format, $targetDir, $fileName)) {
                return $path;
            }
        } catch (\Throwable $e) {
            // Try from ref
        }

        $client = $this->integrations->get($oauth->getAlias());
        if ($client instanceof ZipballInterface) {
            return $client->zipballDownload($oauth, $package->getExternalRef(), $reference, $targetPath);
        }

        $versions = $repository->getPackages();
        if (!$selectedVersion = $this->guessCompletePackage($reference, $versions)) {
            throw new \InvalidArgumentException('Not found reference for the package in cache or VCS');
        }

        return $archiveManager->archive($selectedVersion, $format, $targetDir, $fileName);
    }

    /**
     * @param string $reference
     * @param CompletePackage[]|BasePackage[] $versions
     * @return CompletePackage|null
     */
    private function guessCompletePackage(string $reference, array $versions): ?CompletePackage
    {
        $selectedVersion = $exampleVersion = null;
        foreach ($versions as $rootVersion) {
            if ($rootVersion instanceof CompletePackage) {
                $exampleVersion = $rootVersion;
            }

            if ($reference === $rootVersion->getSourceReference() || $reference === $rootVersion->getDistReference()) {
                $selectedVersion = $rootVersion;
            }
        }

        if ($selectedVersion !== null) {
            return $selectedVersion;
        }

        if ($exampleVersion instanceof CompletePackage) {
            if ($exampleVersion->getDistReference() && str_contains($exampleVersion->getDistUrl(), $exampleVersion->getDistReference())) {
                $exampleVersion->setDistUrl(str_replace($exampleVersion->getDistReference(), $reference, $exampleVersion->getDistUrl()));
                $exampleVersion->setDistReference($reference);
            } else {
                $exampleVersion->setDistType(null);
                $exampleVersion->setDistReference(null);
                $exampleVersion->setDistUrl(null);
            }

            $exampleVersion->setDistMirrors(null);
            $exampleVersion->setSourceMirrors(null);
            if (str_contains($exampleVersion->getSourceUrl(), $exampleVersion->getSourceReference())) {
                $exampleVersion->setSourceUrl(str_replace($exampleVersion->getSourceReference(), $reference, $exampleVersion->getSourceUrl()));
            }

            $exampleVersion->setSourceReference($reference);
            return $exampleVersion;
        }

        return null;
    }

    private function downloadArtifact(string $reference, Package $package): ?string
    {
        if ($path = $this->artifact->moveToLocal($reference)) {
            return $path;
        }

        $repository = $this->createRepositoryAndIo($package);
        $packages = $repository->getPackages();
        $found = array_filter($packages, fn($p) => $reference === $p->getDistReference());

        /** @var PackageInterface $package */
        if ($package = reset($found)) {
            $distUrl = $package->getDistUrl();
            if (is_string($distUrl) && str_starts_with($distUrl, '/')) {
                return $distUrl;
            }
        }

        return null;
    }

    private function downloadVCS(string $reference, Package $package, ?string $versionName = null): ?string
    {
        $repository = $this->createRepositoryAndIo($package);

        $archiveManager = $this->packagistFactory->createArchiveManager($repository->getIO(), $repository);
        $archiveManager->setOverwriteFiles(false);

        $format = $this->config->getArchiveFormat();
        $targetDir = $this->config->generateTargetDir($package->getName());
        $fileName = $this->config->getFileName($reference, $versionName);

        try {
            if ($path = $archiveManager->tryFromGitArchive($reference, $format, $targetDir, $fileName)) {
                return $path;
            }
        } catch (\Exception $e) {
        }

        $probe = $versionName ? $this->tryFromVersion($package, $versionName) : null;
        if (null === $probe || $probe->getSourceReference() !== $reference) {
            $probe = $this->guessCompletePackage($reference, $repository->getPackages());
        }

        if (null !== $probe) {
            return $archiveManager->archive($probe, $this->config->getArchiveFormat(), $targetDir, $fileName);
        }

        return null;
    }

    private function tryFromVersion(Package $package, string $version): ?CompletePackageInterface
    {
        $version = $package->getVersions()->findFirst(fn(Version $ver) => $ver->getVersion() === $version);
        if (null === $version) {
            return null;
        }

        $repo = $this->registry->getRepository(Version::class);
        $data = $repo->getVersionData([$version->getId()]);
        $data = $version->toArray($data);
        unset($data['dist']);
        $loader = new ArrayLoader();

        try {
            return $loader->load($data);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function createRepositoryAndIo(Package $package): PacketonRepositoryInterface
    {
        $io = new NullIO();

        return $this->packagistFactory->createRepository(
            $package->getRepository(),
            $io,
            null,
            $package->getCredentials(),
            $package->getRepoConfig(),
        );
    }

    private function loadCacheOrArchiveFromStorage(string $keyName, bool $useCached = false): mixed
    {
        $filename = $this->config->resolvePath($keyName);

        // For performance always lookup in local cache dir in first
        if (true === $useCached) {
            try {
                $this->fs->touch($filename);
            } catch (\Throwable $e) {
            }
            return $filename;
        }

        $result = true;
        if (!$this->fs->exists($filename)) {
            $stream = $this->baseStorage->readStream($keyName);
            $dirname = dirname($filename);
            if (!$this->fs->exists($dirname)) {
                $this->fs->mkdir($dirname);
            }

            $localCache = @fopen($filename, 'w+b');
            $result = $localCache ? @stream_copy_to_stream($stream, $localCache) : false;
        }

        return $result ? $filename : $this->baseStorage->readStream($keyName);
    }
}
