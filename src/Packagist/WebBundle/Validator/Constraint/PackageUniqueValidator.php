<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Validator\Constraint;

use Doctrine\Common\Persistence\ManagerRegistry;
use Packagist\WebBundle\Entity\Package;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validate composer package name
 */
class PackageUniqueValidator extends ConstraintValidator
{
    protected $doctrine;
    protected $router;

    public function __construct(ManagerRegistry $doctrine, RouterInterface $router)
    {
        $this->doctrine = $doctrine;
        $this->router = $router;
    }

    /**
     * {@inheritdoc}
     * @param PackageUnique $constraint
     */
    public function validate($value, Constraint $constraint)
    {
        if (null === $value || '' === $value) {
            return;
        }

        if (!$value instanceof Package) {
            throw new UnexpectedTypeException($constraint, Package::class);
        }

        $repo = $this->doctrine->getRepository(Package::class);
        if ($name = $value->getName()) {
            try {
                if ($repo->findOneByName($name)) {
                    $this->context
                        ->buildViolation($constraint->packageExists, [
                            '%name%' => $name,
                            '%route_name%' => $this->router->generate('view_package', ['name' => $name])
                        ])
                        ->atPath('repository')
                        ->addViolation()
                    ;
                }
            } catch (\Doctrine\ORM\NoResultException $e) {}
        }
    }
}
