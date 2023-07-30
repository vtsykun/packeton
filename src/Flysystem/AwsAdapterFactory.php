<?php

declare(strict_types=1);

namespace Packeton\Flysystem;

use Aws\S3\S3Client;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class AwsAdapterFactory
{
    public function create(): S3Client
    {
        if (!class_exists(S3Client::class)) {
            throw new \LogicException('You must install "league/flysystem-aws-s3-v3" and "aws/aws-sdk-php" to use S3 adapter');
        }
    }
}
