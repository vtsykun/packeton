<?php

declare(strict_types=1);

namespace Packeton\Validator\Constraint;

use Symfony\Component\Validator\Constraint;

class PackageUnique extends Constraint
{
    public $packageExists = 'A package with the name <a href="%route_name%">%name%</a> already exists.';

    /**
     * {@inheritdoc}
     */
    public function getTargets()
    {
        return self::CLASS_CONSTRAINT;
    }
}
