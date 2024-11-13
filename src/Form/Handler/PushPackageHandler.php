<?php

declare(strict_types=1);

namespace Packeton\Form\Handler;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Package;
use Packeton\Repository\PackageRepository;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

class PushPackageHandler
{
    public function __construct(
        private readonly ManagerRegistry $registry
    ) {
    }

    public function __invoke(FormInterface $form, Request $request, string $name, ?string $version = null): void
    {
        // todo fix PUT support
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new \RuntimeException('todo');
        }

        $dtoRequest = $form->getData();
        $package = $this->getRepo()->getPackageByName($name);
        if (null === $package) {

        }
    }

    private function getRepo(): PackageRepository
    {
        return $this->registry->getRepository(Package::class);
    }

    private function createArtifactPackage(string $name): Package
    {
    }
}
