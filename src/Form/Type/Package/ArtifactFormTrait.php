<?php

declare(strict_types=1);

namespace Packeton\Form\Type\Package;

use Packeton\Entity\Package;
use Packeton\Entity\Zipball;
use Packeton\Util\PacketonUtils;
use Symfony\Component\Form\FormEvent;

trait ArtifactFormTrait
{
    protected function getChoices(bool $unsetUsed = false): array
    {
        $choices = [];
        $all = $this->registry->getRepository(Zipball::class)->ajaxSelect($unsetUsed);
        foreach ($all as $item) {
            $label = $item['filename'] . ' ('.  PacketonUtils::formatSize($item['size']) . ')';
            $choices[$label] = $item['id'];
        }

        return $choices;
    }

    public function setUsageFlag(FormEvent $event): void
    {
        $package = $event->getData();
        if (!$package instanceof Package) {
            return;
        }
        $errors = $event->getForm()->getErrors(true);
        if (count($errors) !== 0) {
            return;
        }

        $repo = $this->registry->getRepository(Zipball::class);
        foreach ($package->getAllArchives() ?: [] as $archive) {
            // When form was submitted and called flush
            $repo->find($archive)?->setUsed(true);
        }
    }

    public function updateRepository(FormEvent $event): void
    {
        $package = $event->getData();
        if ($package instanceof Package) {
            $this->handler->updatePackageUrl($package);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getParent(): string
    {
        return BasePackageType::class;
    }
}
