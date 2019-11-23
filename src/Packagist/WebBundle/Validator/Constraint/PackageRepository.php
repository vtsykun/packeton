<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Validator\Constraint;

use Symfony\Component\Validator\Constraint;

class PackageRepository extends Constraint
{
    /**
     * {@inheritdoc}
     */
    public function getTargets()
    {
        return self::CLASS_CONSTRAINT;
    }
}
