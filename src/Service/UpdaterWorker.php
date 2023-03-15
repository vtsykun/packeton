<?php

declare(strict_types=1);

namespace Packeton\Service;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\AsWorker;
use Packeton\Composer\IO\DebugIO;
use Packeton\Composer\PackagistFactory;
use Packeton\Event\UpdaterErrorEvent;
use Packeton\Exception\JobException;
use Packeton\Model\ConsoleAwareInterface;
use Packeton\Model\ValidatingArrayLoader;
use Packeton\Package\UpdaterFactory;
use Psr\Log\LoggerInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Console\HtmlOutputFormatter;
use Composer\Repository\InvalidRepositoryException;
use Symfony\Component\Console\Output\OutputInterface;
use Packeton\Entity\Package;
use Packeton\Package\Updater;
use Packeton\Entity\Job;
use Packeton\Model\PackageManager;
use Seld\Signal\SignalHandler;
use Composer\Factory;
use Composer\Downloader\TransportException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;

#[AsWorker('package:updates')]
class UpdaterWorker implements ConsoleAwareInterface
{
    private $cmdOutput;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ManagerRegistry $doctrine,
        private readonly UpdaterFactory $updaterFactory,
        private readonly LockFactory $lockFactory,
        private readonly PackageManager $packageManager,
        private readonly PackagistFactory $packagistFactory,
        private readonly EventDispatcherInterface $dispatcher
    ) {}

    public function __invoke(Job $job, SignalHandler $signal): array
    {
        $em = $this->doctrine->getManager();
        $id = $job->getPayload()['id'];
        $packageRepository = $em->getRepository(Package::class);
        /** @var Package $package */
        $package = $packageRepository->find($id);
        if (!$package) {
            $this->logger->info('Package is gone, skipping', ['id' => $id]);

            return ['status' => Job::STATUS_PACKAGE_GONE, 'message' => 'Package was deleted, skipped'];
        }

        $locker = $this->lockFactory->createLock('package_update_' . $id, 1800);
        $lockAcquired = $locker->acquire();
        if (!$lockAcquired) {
            return ['status' => Job::STATUS_RESCHEDULE, 'after' => new \DateTime('+30 seconds')];
        }

        $this->logger->info('Updating '.$package->getName());
        $io = new DebugIO('', OutputInterface::VERBOSITY_VERY_VERBOSE, new HtmlOutputFormatter(Factory::createAdditionalStyles()), 200000);
        $io->setOutput($this->cmdOutput);

        try {
            $config = $this->packagistFactory->createConfig($credentials = $package->getCredentials());
            $io->loadConfiguration($config);
            if ($credentials && empty($credentials->getKey()) && empty($credentials->getComposerConfig())) {
                $io->warning("SSH Key {$credentials->getName()} is not loaded from database, probably encryption param APP_SECRET changed, please check your credentials");
            }

            $flags = 0;
            if ($job->getPayload()['update_equal_refs'] === true) {
                $flags = Updater::UPDATE_EQUAL_REFS;
            }
            if ($job->getPayload()['delete_before'] === true) {
                $flags = Updater::DELETE_BEFORE;
            }

            $updater = $this->updaterFactory->createUpdater($package->getRepoType());

            // prepare dependencies
            $loader = new ValidatingArrayLoader(new ArrayLoader());

            // prepare repository
            $repository = $this->packagistFactory->createRepository($package->getRepository(), $io, $config, null, $package->getRepoConfig());
            if (method_exists($repository, 'setLoader')) {
                $repository->setLoader($loader);
            }

            // perform the actual update (fetch and re-scan the repository's source)
            $package = $updater->update($io, $config, $package, $repository, $flags);
        } catch (\Throwable $e) {
            $output = $io->getOutput();

            if (!$this->doctrine->getManager()->isOpen()) {
                $this->doctrine->resetManager();
                $package = $this->doctrine->getManager()->getRepository(Package::class)->findOneById($package->getId());
            } else {
                // reload the package just in case as Updater tends to merge it to a new instance
                $package = $packageRepository->find($id);
            }

            try {
                $this->dispatcher->dispatch(new UpdaterErrorEvent($package, $e, $output), UpdaterErrorEvent::PACKAGE_ERROR);
            } catch (\Throwable $e) {
                $this->logger->error('Events trigger fails: ' . $e->getMessage(), ['e' => $e]);
            }

            // invalid composer data somehow, notify the owner and then mark the job failed
            if ($e instanceof InvalidRepositoryException) {
                $this->packageManager->notifyUpdateFailure($package, $e, $output);

                return [
                    'status' => Job::STATUS_FAILED,
                    'message' => 'Update of '.$package->getName().' failed, invalid composer.json metadata',
                    'details' => '<pre>'.$output.'</pre>',
                    'exception' => $e,
                ];
            }

            $found404 = false;

            // attempt to detect a 404/dead repository
            // TODO check and delete those packages with crawledAt in the far future but updatedAt in the past in a second step/job if the repo is really unreachable
            // probably should check for download count and a few other metrics to avoid false positives and ask humans to check the others
            if ($e instanceof \RuntimeException && strpos($e->getMessage(), 'remote: Repository not found')) {
                // git clone was attempted and says the repo is not found, that's very conclusive
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && strpos($e->getMessage(), 'git@gitlab.com') && strpos($e->getMessage(), 'Please make sure you have the correct access rights')) {
                // git clone says we have no right on gitlab for 404s
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && strpos($e->getMessage(), 'git@bitbucket.org') && strpos($e->getMessage(), 'Please make sure you have the correct access rights')) {
                // git clone says we have no right on bitbucket for 404s
                $found404 = true;
            } elseif ($e instanceof \RuntimeException && strpos($e->getMessage(), '@github.com/') && strpos($e->getMessage(), ' Please ask the owner to check their account')) {
                // git clone says account is disabled on github for private repos(?) if cloning via https
                $found404 = true;
            } elseif ($e instanceof TransportException && preg_match('{https://api.bitbucket.org/2.0/repositories/[^/]+/.+?\?fields=-project}i', $e->getMessage()) && $e->getStatusCode() == 404) {
                // bitbucket api root returns a 404
                $found404 = true;
            }

            // detected a 404 so mark the package as gone and prevent updates for 1y
            if ($found404) {
                return [
                    'status' => Job::STATUS_PACKAGE_GONE,
                    'message' => 'Update of '.$package->getName().' failed, package appears to be 404/gone and has been marked as crawled for 1year',
                    'details' => '<pre>'.$output.'</pre>',
                    'exception' => $e,
                ];
            }

            // Catch request timeouts e.g. gitlab.com
            if ($e instanceof TransportException && strpos($e->getMessage(), 'file could not be downloaded: failed to open stream: HTTP request failed!')) {
                return [
                    'status' => Job::STATUS_FAILED,
                    'message' => 'Package data of '.$package->getName().' could not be downloaded. Could not reach remote VCS server. Please try again later.',
                    'exception' => $e,
                    'details' => '<pre>'.$output.'</pre>',
                ];
            }

            // generic transport exception
            if ($e instanceof TransportException) {
                return [
                    'status' => Job::STATUS_FAILED,
                    'message' => 'Package data of '.$package->getName().' could not be downloaded.',
                    'exception' => $e,
                    'details' => '<pre>'.$output.'</pre>',
                ];
            }

            $this->logger->error('Failed update of '.$package->getName(), ['exception' => $e]);

            throw new JobException($e, $output ? '<pre>'.$output.'</pre>' : null);
        } finally {
            $locker->release();
        }

        return [
            'status' => Job::STATUS_COMPLETED,
            'message' => 'Update of '.$package->getName().' complete',
            'details' => '<pre>' . substr($io->getOutput(), 0, 1000000) . '</pre>'
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function setOutput(OutputInterface $output = null): void
    {
        $this->cmdOutput = $output;
    }
}
