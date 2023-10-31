<?php

declare(strict_types=1);

namespace Packeton\Validator\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 */
class ValidRegex extends Constraint
{
    public $message = 'This value is not a valid regular expression pattern.';
}
