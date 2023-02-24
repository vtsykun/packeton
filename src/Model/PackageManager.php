<?php

declare(strict_types=1);

namespace Packeton\Model;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Composer\Cache\MetadataCache;
use Packeton\Composer\MetadataMinifier;
use Packeton\Composer\PackagistFactory;
use Packeton\Entity\User;
use Packeton\Entity\Version;
use Packeton\Event\UpdaterEvent;
use Packeton\Package\InMemoryDumper;
use Packeton\Repository\VersionRepository;
use Packeton\Entity\Package;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

class PackageManager
{
    public function __construct(
        protected ManagerRegistry $doctrine,
        protected MailerInterface $mailer,
        protected Environment $twig,
        protected LoggerInterface $logger,
        protected ProviderManager $providerManager,
        protected InMemoryDumper $dumper,
        protected AuthorizationCheckerInterface $authorizationChecker,
        protected PackagistFactory $packagistFactory,
        protected EventDispatcherInterface $dispatcher,
        protected MetadataCache $cache,
        protected MetadataMinifier $metadataMinifier,
        protected \Redis $redis,
    ) {}

    public function deletePackage(Package $package)
    {
        /** @var VersionRepository $versionRepo */
        $versionRepo = $this->doctrine->getRepository(Version::class);
        $this->dispatcher->dispatch(new UpdaterEvent($package), UpdaterEvent::PACKAGE_REMOVE);

        foreach ($package->getVersions() as $version) {
            $versionRepo->remove($version);
        }

        $this->providerManager->deletePackage($package);

        $em = $this->doctrine->getManager();
        $em->remove($package);
        $em->flush();
    }

    /**
     * @param Package $package
     */
    public function updatePackageUrl(Package $package): void
    {
        if (!$package->getRepository() || $package->vcsDriver === true) {
            return;
        }
        // avoid user@host URLs
        if (preg_match('{https?://.+@}', $package->getRepository())) {
            return;
        }

        try {
            $repository = $this->packagistFactory->createRepository(
                $package->getRepository(),
                null,
                null,
                $package->getCredentials()
            );

            $driver = $package->vcsDriver = $repository->getDriver();
            if (!$driver) {
                return;
            }
            $information = $driver->getComposerInformation($driver->getRootIdentifier());
            if (!isset($information['name'])) {
                return;
            }
            if (null === $package->getName()) {
                $package->setName($information['name']);
            }
        } catch (\Exception $e) {
            $package->vcsDriverError = '['.get_class($e).'] '.$e->getMessage();
        }
    }

    public function notifyUpdateFailure(Package $package, \Exception $e, $details = null)
    {
        if (!$package->isUpdateFailureNotified()) {
            $recipients = [];
            foreach ($package->getMaintainers() as $maintainer) {
                if ($maintainer->isNotifiableForFailures()) {
                    $recipients[] = $maintainer->getEmail();
                }
            }

            if ($recipients) {
                $body = $this->twig->render('email/update_failed.txt.twig', [
                    'package' => $package,
                    'exception' => get_class($e),
                    'exceptionMessage' => $e->getMessage(),
                    'details' => strip_tags($details),
                ]);

                try {
                    $email = (new Email())
                        ->to(...$recipients)
                        ->subject($package->getName().' failed to update, invalid composer.json data')
                        ->html($body);

                    $this->mailer->send($email);
                } catch (\Throwable $e) {
                    $this->logger->error('['.get_class($e).'] ' . $e->getMessage(), ['e' => $e]);
                    return false;
                }
            }

            $package->setUpdateFailureNotified(true);
            $this->doctrine->getManager()->flush();
        }

        return true;
    }

    public function getRootPackagesJson(UserInterface $user = null)
    {
        $packagesData = $this->dumpInMemory($user, false);
        return $packagesData[0];
    }

    /**
     * @param User|null|object $user
     * @param string $hash
     * @return bool
     */
    public function getProvidersJson(?UserInterface $user, $hash)
    {
        list($root, $providers) = $this->dumpInMemory($user);
        $rootHash = \reset($root['provider-includes']);
        if ($hash && $rootHash['sha256'] !== $hash) {
            return false;
        }

        return $providers;
    }

    /**
     * @param null|UserInterface|object $user
     * @param string $package
     *
     * @return array
     */
    public function getPackageJson(?UserInterface $user, string $package)
    {
        if ($user && $this->authorizationChecker->isGranted('ROLE_FULL_CUSTOMER')) {
            $user = null;
        }

        return $this->dumper->dumpPackage($user, $package);
    }

    public function getPackageV2Json(?UserInterface $user, string $package, bool $isDev = true, &$lastModified = null): array
    {
        $metadata = $this->getCachedPackageJson($user, $package) ?:
            $this->getPackageJson($user, $package);

        if (empty($metadata)) {
            return [];
        }

        return $this->metadataMinifier->minify($metadata, $isDev, $lastModified);
    }

    /**
     * @param UserInterface|null|object $user
     * @param string $package
     * @param string|null $hash
     *
     * @return mixed
     */
    public function getCachedPackageJson(?UserInterface $user, string $package, string $hash = null)
    {
        [$root, $providers, $packages] = $this->dumpInMemory($user);

        if (false === isset($providers['providers'][$package]) ||
            ($hash && $providers['providers'][$package]['sha256'] !== $hash)
        ) {
            return false;
        }

        return $packages[$package];
    }

    private function dumpInMemory(UserInterface $user = null, bool $ignoreLastModify = true)
    {
        if ($user && $this->authorizationChecker->isGranted('ROLE_FULL_CUSTOMER')) {
            $user = null;
        }

        $cacheKey = 'pkg_user_cache_' . ($user ? $user->getUserIdentifier() : 0);

        $lastModify = false === $ignoreLastModify ? ($this->getLastModify()?->getTimestamp() ?: 0) : null;
        return $this->cache->get($cacheKey, fn () => $this->dumper->dump($user), $lastModify);
    }

    public function getPackageNames(): array
    {
        $cacheKey = 'all_packages_';
        $lastModify = $this->getLastModify()?->getTimestamp();
        $data = $this->cache->get($cacheKey, fn () => $this->doctrine->getRepository(Package::class)->getPackageNames(), $lastModify);

        return is_array($data) ? $data : [];
    }

    public function setLastModify(): void
    {
        try {
            $this->redis->set('packages-last-modify', time());
        } catch (\Exception) {}
    }

    public function getLastModify(): ?\DateTimeInterface
    {
        $unix = $this->redis->get('packages-last-modify');
        return $unix ? \DateTime::createFromFormat('U', $unix) : null;
    }
}
