<?php

declare(strict_types=1);

namespace Packeton\Form\Type\Package;

use Packeton\Entity\Package;
use Symfony\Component\Form\FormEvent;

trait VcsPackageTypeTrait
{
    public function updateRepository(FormEvent $event): void
    {
        $package = $event->getData();
        if ($package instanceof Package) {
            $this->packageManager->updatePackageUrl($package);
        }
    }
}
