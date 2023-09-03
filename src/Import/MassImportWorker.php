<?php

declare(strict_types=1);

namespace Packeton\Import;

use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\AsWorker;
use Packeton\Entity\Job;
use Packeton\Entity\OAuthIntegration;
use Packeton\Entity\Package;
use Packeton\Entity\SshCredentials;
use Packeton\Entity\User;
use Packeton\Integrations\IntegrationRegistry;
use Packeton\Integrations\Model\AppUtils;
use Packeton\Model\PackageManager;
use Packeton\Package\RepTypes;
use Packeton\Service\Scheduler;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsWorker('mass:import')]
class MassImportWorker
{
    public function __construct(
        protected ManagerRegistry $registry,
        protected IntegrationRegistry $integrations,
        protected PackageManager $packageManager,
        protected ValidatorInterface $validator,
        protected Scheduler $scheduler,
    ) {
    }

    public function __invoke(Job $job): array
    {
        $payload = $job->getPayload();

        if ($repos = $payload['repos'] ?? null) {
            return [];
        }

        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager();

        $io = new NullIO();
        foreach ($repos as $id => $repo) {
            $package = $payload['type'] === 'integration' ? $this->integrationImport($io, $payload, $repo, $id) :
                $this->standaloneImport($io, $payload, $repo);

            if (null === $package) {
                continue;
            }

            $output = '';
            $errors = $this->validator->validate($package, null, ['Create', 'Default']);
            foreach ($errors as $error) {
                $output .= "[{$error->getPropertyPath()}] {$error->getMessage()}\n";
            }
            if ($output) {
                $io->notice("Package $repo: {$package->getName()} validation error\n" . $output);
                continue;
            }

            $em->persist($package);
            $em->flush();

            $this->packageManager->insetPackage($package);
            $this->scheduler->scheduleUpdate($package);
            $io->info("Import a new package {$package->getName()}\n");

            $em->clear();
        }

        return [];
    }

    protected function integrationImport(IOInterface $io, array $payload, string $repoUrl, string|int $externalId): ?Package
    {
        $integration = $this->registry->getRepository(OAuthIntegration::class)->find($payload['integration']);
        $app = $this->integrations->findApp($integration->getAlias());

        $opts = ['clone_preference' => $payload['clone'] === 'ssh' ? 'clone_ssh' : ($payload['clone'] === 'api' ? 'api' : null)];
        $config = $app->getConfig()->withOptions(array_filter($opts));

        try {
            $repoUrl = AppUtils::findUrl((string)$externalId, $integration, $app, $config);
        } catch (\Throwable $e) {
        }

        $user = ($payload['user'] ?? null) ? $this->registry->getRepository(User::class)->find($payload['user']) : null;
        $package = new Package();
        $package->setRepository($repoUrl);
        $package->setExternalRef((string)$externalId);

        if (null !== $user) {
            $package->addMaintainer($user);
        }

        $owner = $integration->getOwner() ? $this->registry->getRepository(User::class)->findOneByUsernameOrEmail($integration->getOwner()) : null;
        if ($owner instanceof User && $user !== $owner) {
            $package->addMaintainer($owner);
        }

        if ($payload['clone'] === 'ssh') {
            $credential = ($payload['credentials'] ?? null) ? $this->registry->getRepository(SshCredentials::class)->find($payload['credentials']) : null;
            $package->setRepoType(RepTypes::VCS);
            $package->setCredentials($credential);
        } else {
            $package->setIntegration($integration);
            $package->setRepoType(RepTypes::INTEGRATION);
        }

        try {
            $this->packageManager->updatePackageUrl($package);
        } catch (\Throwable $e) {
            $msg = AppUtils::castError($e, $integration, true);
            $io->error("Update $repoUrl failed: " . $msg);
            return null;
        }

        return $package;
    }

    protected function standaloneImport(IOInterface $io, array $payload, string $repoUrl): ?Package
    {
        $credential = ($payload['credentials'] ?? null) ? $this->registry->getRepository(SshCredentials::class)->find($payload['credentials']) : null;
        $user = ($payload['user'] ?? null) ? $this->registry->getRepository(User::class)->find($payload['user']) : null;

        $package = new Package();
        $package->setRepoType(RepTypes::VCS);
        $package->setCredentials($credential);
        if (null !== $user) {
            $package->addMaintainer($user);
        }

        $package->setRepository($repoUrl);

        try {
            $this->packageManager->updatePackageUrl($package);
        } catch (\Throwable $e) {
            $io->error("Update $repoUrl failed: " . $e->getMessage());
            return null;
        }

        return $package;
    }
}
