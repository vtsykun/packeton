<?php

declare(strict_types=1);

namespace Packeton\Form\Model;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

class NexusPushRequestDto implements PushRequestDtoInterface
{
    public function __construct(
        #[Assert\NotBlank]
        public ?UploadedFile $package = null,
        public ?string $srcType = null,
        public ?string $srcUrl = null,
        public ?string $srcRef = null,
        public ?string $name = null,
        public ?string $version = null,
    ) {
    }

    public function getArtifact(): File
    {
        return $this->package;
    }

    public function getPackageName(): string
    {
        return $this->name;
    }

    public function getPackageVersion(): string
    {
        return $this->version;
    }
}
