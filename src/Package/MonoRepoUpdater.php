<?php

declare(strict_types=1);

namespace Packeton\Package;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryInterface;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Composer\PackagistFactory;
use Packeton\Composer\PacketonRepositoryFactory;
use Packeton\Composer\Repository\Vcs\TreeGitDriver;
use Packeton\Composer\Repository\VcsRepository;
use Packeton\Entity\Package;
use Packeton\Entity\User;
use Packeton\Util\PacketonUtils;
use Seld\Signal\SignalHandler;

class MonoRepoUpdater implements UpdaterInterface
{
    protected $diffCache = [];

    public function __construct(
        protected ManagerRegistry $registry,
        protected Updater $vcsUpdater,
        protected PackagistFactory $factory,
        protected PacketonRepositoryFactory $repositoryFactory,
        protected string $packageRegexp,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public static function supportRepoTypes(): iterable
    {
        return ['mono-repo'];
    }

    /**
     * {@inheritdoc}
     */
    public function update(IOInterface $io, Config $config, Package $package, RepositoryInterface $repository, int $flags = 0, SignalHandler $signal = null): Package
    {
        if (!$repository instanceof VcsRepository) {
            $io->error("Only vcs repos support for mono-repo type, skip update");
            return $package;
        }

        $this->diffCache = [];
        $em = $this->registry->getManager();
        $repo = $this->registry->getRepository(Package::class);
        $driver = new TreeGitDriver(
            $repository->getRepoConfig() + ['url' => $package->getRepository()],
            $io,
            $repository->getConfig(),
            $repository->getHttpDownloader(),
            $repository->getProcessExecutor(),
        );

        $driver->initialize();

        $exception = null;
        $alreadyProcessed = [];

        $listFiles = $driver->getRepoTree();
        $listOfResources = PacketonUtils::matchGlob($listFiles, $package->getGlob(), $package->getExcludedGlob());
        $listOfResources = array_map(fn($res) => dirname($res), $listOfResources);

        foreach ($listOfResources as $resource) {
            $io->info("Reading repository path $resource/composer.json");
            $baseConfig = $repository->getRepoConfig();
            $subDirectory = $baseConfig['subDirectory'] = $resource;

            /** @var VcsRepository $subRepo */
            $subRepo = $this->repositoryFactory->create($baseConfig, $repository->getIO(), $repository->getConfig());
            $subRepo->setDriver($driver->withSubDirectory($subDirectory));

            try {
                if (!$packages = $subRepo->getPackages()) {
                    $io->notice("Not found packages in the sub-directory $subDirectory");
                    continue;
                }
            } catch (\Exception $e) {
                $io->error("Error when process packages for $subDirectory:" . $e->getMessage());
                $exception = $e;
                continue;
            }

            $packages = PacketonUtils::sort($packages);
            /** @var PackageInterface $template */
            $template = $packages[0];
            $packageName = $template->getName();
            if (isset($alreadyProcessed[$packageName])) {
                $io->warning("The package $packageName already exists in the directory {$alreadyProcessed[$packageName]}");
                continue;
            }

            if (!\preg_match('#^'. $this->packageRegexp. '$#', $packageName)) {
                $io->error("The package name is invalid $packageName, allowed: {$this->packageRegexp}");
                continue;
            }

            $subPackage = $repo->findOneByName($packageName);
            if ($subPackage === null) {
                $subPackage = new Package();
                $subPackage->setName($packageName);
                $subPackage->setRepository($package->getRepository());
                $subPackage->setParentPackage($package);
                $subPackage->setSubDirectory($subDirectory);

                // if em is clear
                foreach ($package->getMaintainers() as $maintainer) {
                    if ($maintainer = $em->find(User::class, $maintainer->getId())) {
                        $subPackage->addMaintainer($maintainer);
                    }
                }

                $em->persist($subPackage);
                $em->flush();
            }

            if ($subPackage->getParentPackage()?->getId() !== $package->getId()) {
                $io->error("Package with the same name '$packageName' already exists in the packagist");
                continue;
            }

            $prevSubDir = $subPackage->getSubDirectory();
            if ($prevSubDir && $prevSubDir !== $subDirectory && in_array($prevSubDir, $listOfResources)) {
                $io->error("The package $packageName define into two dirs $prevSubDir, $subDirectory at the same time was skipped.");
                continue;
            }

            $subPackage->setSubDirectory($subDirectory);
            $subPackage->setRepository($package->getRepository());
            $subPackage->setAutoUpdated(true);
            $em->flush();

            if ($package->isSkipNotModifyTag()) {
                $this->processEmptyTags($driver, $subDirectory, $packages, $subRepo);
            }

            try {
                $this->vcsUpdater->update($io, $config, $subPackage, $subRepo, $flags, $signal);
            } catch (\Exception $e) {
                $io->error("Error when process package $packageName: " . $e->getMessage());
                $exception = $e;
                continue;
            }

            $em->clear();
            $package = $em->find(Package::class, $package->getId());
            $alreadyProcessed[$packageName] = $subDirectory;
            if ($signal->isTriggered()) {
                $io->warning("Mono-repo sync interrupted by signal");
                return $package;
            }
        }

        try {
            if ($driver->hasComposerFile($driver->getRootIdentifier())) {
                $em->clear();
                $package = $em->find(Package::class, $package->getId());

                $repository->setDriver($driver);
                $this->vcsUpdater->update($io, $config, $package, $repository, $flags, $signal);
            }
        } catch (\Exception $e) {
            $io->error($e->getMessage());
        }

        $this->diffCache = [];
        if (empty($alreadyProcessed) && null !== $exception) {
            throw $exception;
        }

        return $package;
    }

    protected function processEmptyTags(TreeGitDriver $driver, string $subDirectory, array $packages, VcsRepository $repository): void
    {
        /** @var PackageInterface $package */
        $prevPackage = null;
        foreach ($packages as $package) {
            if ($prevPackage !== null && !$package->isDev()) {
                $ver1 = explode('.', $prevPackage->getVersion());
                $ver2 = explode('.', $package->getVersion());

                if ($package->getSourceReference() === null
                    || $prevPackage->getSourceReference() === null
                    || !isset($ver1[2], $ver2[2]) || $ver1[1] !== $ver2[1] || $ver1[0] !== $ver2[0]
                    || $prevPackage->getStability() !== $package->getStability()
                ) {
                    $prevPackage = $package;
                    continue;
                }

                $key = [$prevPackage->getSourceReference(), $package->getSourceReference()];
                sort($key);
                $key = sha1(json_encode($key));

                $this->diffCache[$key] ??= $driver->getDiff($package->getSourceReference(), $prevPackage->getSourceReference());
                if (!$this->hasChanges($subDirectory, $this->diffCache[$key])) {
                    $repository->removePackage($package);
                }
            }

            $prevPackage = $package;
        }
    }

    protected function hasChanges(string $subDirectory, array $diff): bool
    {
        $subDirectory = trim($subDirectory, '/') . '/';
        foreach ($diff as $name) {
            if (str_starts_with($name, $subDirectory)) {
                return true;
            }
        }

        return false;
    }
}
