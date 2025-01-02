<?php

declare(strict_types=1);

namespace Packeton\Service\Artifact;

use Packeton\Entity\Package;
use Packeton\Form\Model\PushRequestDtoInterface;
use Packeton\Model\UploadZipballStorage;

class ArtifactPushHandler
{
    public function __construct(
        private readonly UploadZipballStorage $storage
    ) {
    }

    public function addVersion(PushRequestDtoInterface $requestDto, Package $package, ?string $version = null): void
    {
        $version ??= 'dev-master';

        $this->storage->save($requestDto->getArtifact());
    }
}
