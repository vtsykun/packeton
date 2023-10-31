<?php

declare(strict_types=1);

namespace Packeton\Integrations;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Attribute\AsWorker;
use Packeton\Entity\Job;
use Packeton\Entity\OAuthIntegration;
use Packeton\Entity\Package;
use Packeton\Entity\User;
use Packeton\Integrations\Model\AppUtils;
use Packeton\Model\PackageManager;
use Packeton\Package\RepTypes;
use Packeton\Service\Scheduler;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsWorker('integration:repo:sync')]
class RemoteReposSyncWorker
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

        $em = $this->registry->getManager();
        $app = $this->registry->getRepository(OAuthIntegration::class)->find($payload['app']);
        $client = $this->integrations->findApp($app->getAlias());

        try {
            $url = AppUtils::findUrl($payload['external_id'], $app, $client);
        } catch (\InvalidArgumentException $e) {
            $client->cacheClear($app->getId());
            $url = AppUtils::findUrl($payload['external_id'], $app, $client);
        }

        $owner = $app->getOwner() ? $this->registry->getRepository(User::class)->findOneByUsernameOrEmail($app->getOwner()) : null;
        $package = new Package();
        if ($owner instanceof User) {
            $package->addMaintainer($owner);
        }

        $package->setExternalRef($payload['external_id']);
        $package->setRepository($url);
        $package->setAutoUpdated(true);

        if (AppUtils::clonePref($client->getConfig(), $app) === 'clone_ssh') {
            $package->setRepoType(RepTypes::VCS);
        } else {
            $package->setIntegration($app);
            $package->setRepoType(RepTypes::INTEGRATION);
        }

        $this->packageManager->updatePackageUrl($package);
        $errors = $this->validator->validate($package, null, ['Create']);
        if (count($errors) > 0) {
            $output = '';
            foreach ($errors as $error) {
                $output .= "[{$error->getPropertyPath()}] {$error->getMessage()}\n";
            }
            return [
                'status' => Job::STATUS_FAILED,
                'message' => "composer.json data in repo {$payload['external_id']} is invalid",
                'details' => '<pre>'.$output.'</pre>',
            ];
        }

        $em->persist($package);
        $em->flush();

        $this->packageManager->insetPackage($package);
        $this->scheduler->scheduleUpdate($package);

        return [
            'status' => Job::STATUS_COMPLETED,
            'message' => "A new package {$package->getName()} was created successfully",
        ];
    }
}
