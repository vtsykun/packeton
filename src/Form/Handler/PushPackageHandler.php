<?php

declare(strict_types=1);

namespace Packeton\Form\Handler;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Package;
use Packeton\Entity\User;
use Packeton\Form\Model\PushRequestDtoInterface;
use Packeton\Model\PackageManager;
use Packeton\Package\RepTypes;
use Packeton\Repository\PackageRepository;
use Packeton\Service\Artifact\ArtifactPushHandler;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

class PushPackageHandler
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly PackageManager $packageManager,
        private readonly ArtifactPushHandler $handler,
    ) {
    }

    public function __invoke(FormInterface $form, Request $request, string $name, ?string $version = null, ?UserInterface $user = null): void
    {
        // todo fix PUT support
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new \RuntimeException('todo');
        }

        /** @var PushRequestDtoInterface $artifact */
        $artifact = $form->getData();
        $package = $this->getRepo()->getPackageByName($name);
        if (null === $package) {
            $package = $this->createArtifactPackage($name, $user);
        }


    }

    private function getRepo(): PackageRepository
    {
        return $this->registry->getRepository(Package::class);
    }

    private function createArtifactPackage(string $name, ?UserInterface $user = null): Package
    {
        $em = $this->registry->getManager();
        $package = new Package();
        $package->setName($name);
        $package->setRepoType(RepTypes::ARTIFACT);

        if ($user instanceof User) {
            $package->addMaintainer($user);
        }

        $em->persist($package);
        $em->flush();

        $this->packageManager->insetPackage($package);

        return $package;
    }
}
