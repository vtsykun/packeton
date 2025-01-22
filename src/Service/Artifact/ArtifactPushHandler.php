<?php

declare(strict_types=1);

namespace Packeton\Service\Artifact;

use Composer\IO\IOInterface;
use Composer\Util\Tar;
use Composer\Util\Zip;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Package;
use Packeton\Entity\Zipball;
use Packeton\Exception\ValidationException;
use Packeton\Form\Model\PushRequestDtoInterface;
use Packeton\Model\UploadZipballStorage;
use Packeton\Package\RepTypes;
use Packeton\Service\Scheduler;

class ArtifactPushHandler
{
    public function __construct(
        private readonly UploadZipballStorage $storage,
        private readonly ManagerRegistry $registry,
        private readonly Scheduler $scheduler,
    ) {
    }

    public function addVersion(PushRequestDtoInterface $requestDto, Package $package): void
    {
        $zip = $this->storage->save($requestDto->getArtifact());
        $zip->setUsed(true);

        $path = $this->storage->moveToLocal($zip);
        if (!file_exists($path)) {
            throw new ValidationException("Unable to create archive file", 400);
        }

        switch ($package->getRepoType()) {
            case RepTypes::ARTIFACT:
                $package->setArchiveOverwrite(
                    $zip->getId(),
                    $this->generateArchiveData($requestDto)
                );
                break;
            case RepTypes::CUSTOM:
                $package->addCustomVersions(
                    $this->generateCustomData($requestDto, $zip, $path)
                );
                break;
            default:
                throw new ValidationException("Support only CUSTOM and ARTIFACT repository types", 400);
        }

        $manager = $this->registry->getManager();
        $manager->flush();

        $this->scheduler->scheduleUpdate($package);
    }

    private function generateArchiveData(PushRequestDtoInterface $requestDto): array
    {
        return [
            'version' => $requestDto->getPackageVersion(),
            'source' => $requestDto->getSource(),
        ];
    }

    private function generateCustomData(PushRequestDtoInterface $requestDto, Zipball $zipball, string $path): array
    {
        $file = new \SplFileInfo($path);
        $fileExtension = pathinfo($file->getPathname(), PATHINFO_EXTENSION);

        if (in_array($fileExtension, ['gz', 'tar', 'tgz'], true)) {
            $fileType = 'tar';
        } elseif ($fileExtension === 'zip') {
            $fileType = 'zip';
        } else {
            throw new ValidationException('Files with "'.$fileExtension.'" extensions aren\'t supported. Only ZIP and TAR/TAR.GZ/TGZ archives are supported.');
        }

        try {
            $json = $fileType === 'tar' ? Tar::getComposerJson($file->getPathname()) : Zip::getComposerJson($file->getPathname());
        } catch (\Throwable $e) {
            throw new ValidationException('Failed loading package '.$file->getPathname().': '.$e->getMessage(), previous: $e);
        }

        $json = json_decode($json, true) ?? [];

        $json['version'] = $requestDto->getPackageVersion();
        $json['source'] ??= $requestDto->getSource();

        unset($json['extra']['push']);

        return [
            'version' => $requestDto->getPackageVersion(),
            'dist' => $zipball->getId(),
            'definition' => $json,
        ];
    }
}
