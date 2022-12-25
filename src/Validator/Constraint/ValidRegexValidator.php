<?php

namespace Packagist\WebBundle\Validator\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ValidRegexValidator extends ConstraintValidator
{
    /**
     * {@inheritdoc}
     */
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidRegex) {
            throw new UnexpectedTypeException($constraint, ValidRegex::class);
        }

        if ($value === '' || $value === null) {
            return;
        }

        try {
            if (false === @preg_match($value, '')) {
                $this->context->buildViolation($constraint->message)
                    ->addViolation();
            }
        } catch (\Throwable $exception) {
            $this->context->buildViolation($constraint->message)
                ->addViolation();
        }
    }
}
