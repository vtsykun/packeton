<?php

declare(strict_types=1);

namespace Packeton\Form\Model;

use Symfony\Component\HttpFoundation\File\File;

interface PushRequestDtoInterface
{
    public function getArtifact(): File;

    public function getPackageName(): string;

    public function getPackageVersion(): string;

    public function getSource(): ?array;
}
