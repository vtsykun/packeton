<?php

namespace Packeton\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Packeton\Entity\Package;

trait ControllerTrait
{
    /**
     * @return \Doctrine\Persistence\ObjectManager|EntityManagerInterface
     */
    protected function getEM()
    {
        return $this->registry->getManager();
    }

    protected function getPackagesMetadata($packages)
    {
        $ids = [];

        if (!count($packages)) {
            return [];
        }

        $favs = [];
        $solarium = false;
        foreach ($packages as $package) {
            if ($package instanceof \Solarium_Document_ReadOnly) {
                $solarium = true;
                $ids[] = $package->id;
            } elseif ($package instanceof Package) {
                $ids[] = $package->getId();
                $favs[$package->getId()] = $this->favoriteManager->getFaverCount($package);
            } elseif (is_array($package)) {
                $solarium = true;
                $ids[] = $package['id'];
            } else {
                throw new \LogicException('Got invalid package entity');
            }
        }

        if ($solarium) {
            return [
                'downloads' => $this->downloadManager->getPackagesDownloads($ids),
                'favers' => $this->favoriteManager->getFaverCounts($ids),
            ];
        }

        return [
            'downloads' => $this->downloadManager->getPackagesDownloads($ids),
            'favers' => $favs,
        ];
    }
}
