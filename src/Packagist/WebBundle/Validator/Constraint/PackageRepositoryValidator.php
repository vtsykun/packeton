<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Validator\Constraint;

use Packagist\WebBundle\Entity\Package;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class PackageRepositoryValidator extends ConstraintValidator
{
    /**
     * {@inheritdoc}
     * @param PackageRepository $constraint
     */
    public function validate($value, Constraint $constraint)
    {
        if (null === $value || '' === $value) {
            return;
        }

        if (!$value instanceof Package) {
            throw new UnexpectedTypeException($constraint, Package::class);
        }

        // vcs driver was not nulled which means the repository was not set/modified and is still valid
        if (true === $value->vcsDriver && null !== $value->getName()) {
            return;
        }
        if (null === $value->getRepository()) {
            return;
        }

        $property = 'repository';
        $driver = $value->vcsDriver;
        if (!is_object($driver)) {
            if (preg_match('{https?://.+@}', $value->getRepository())) {
                $this->context->buildViolation('URLs with user@host are not supported, use a read-only public URL')
                    ->atPath($property)
                    ->addViolation()
                ;
            } elseif (is_string($value->vcsDriverError)) {
                $this->context->buildViolation('Uncaught Exception: '.htmlentities($value->vcsDriverError, ENT_COMPAT, 'utf-8'))
                    ->atPath($property)
                    ->addViolation()
                ;
            } else {
                $this->context->buildViolation('No valid/supported repository was found at the given URL')
                    ->atPath($property)
                    ->addViolation()
                ;
            }
            return;
        }

        try {
            $information = $driver->getComposerInformation($driver->getRootIdentifier());
            if (false === $information) {
                $this->context->buildViolation('No composer.json was found in the '.$driver->getRootIdentifier().' branch.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            if (empty($information['name'])) {
                $this->context->buildViolation('The package name was not found in the composer.json, make sure there is a name present.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            if (!preg_match('{^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$}i', $information['name'])) {
                $this->context->buildViolation('The package name '.htmlentities($information['name'], ENT_COMPAT, 'utf-8').' is invalid, it should have a vendor name, a forward slash, and a package name. The vendor and package name can be words separated by -, . or _. The complete name should match "[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*".')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            if (preg_match('{(free.*watch|watch.*free|movie.*free|free.*movie|watch.*movie|watch.*full|generate.*resource|generate.*unlimited|hack.*coin|coin.*hack|v[.-]?bucks|(fortnite|pubg).*free|hack.*cheat|cheat.*hack|putlocker)}i', $information['name'])) {
                $this->context->buildViolation('The package name '.htmlentities($information['name'], ENT_COMPAT, 'utf-8').' is blocked, if you think this is a mistake please get in touch with us.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            $reservedNames = ['nul', 'con', 'prn', 'aux', 'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9', 'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9'];
            $bits = explode('/', strtolower($information['name']));
            if (in_array($bits[0], $reservedNames, true) || in_array($bits[1], $reservedNames, true)) {
                $this->context->buildViolation('The package name '.htmlentities($information['name'], ENT_COMPAT, 'utf-8').' is reserved, package and vendor names can not match any of: '.implode(', ', $reservedNames).'.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            if (preg_match('{\.json$}', $information['name'])) {
                $this->context->buildViolation('The package name '.htmlentities($information['name'], ENT_COMPAT, 'utf-8').' is invalid, package names can not end in .json, consider renaming it or perhaps using a -json suffix instead.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            if (preg_match('{[A-Z]}', $information['name'])) {
                $suggestName = preg_replace('{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}', '\\1\\3-\\2\\4', $information['name']);
                $suggestName = strtolower($suggestName);

                $this->context->buildViolation('The package name '.htmlentities($information['name'], ENT_COMPAT, 'utf-8').' is invalid, it should not contain uppercase characters. We suggest using '.$suggestName.' instead.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }
        } catch (\Exception $e) {
            $this->context->buildViolation('We had problems parsing your composer.json file, the parser reports: '.htmlentities($e->getMessage(), ENT_COMPAT, 'utf-8'))
                ->atPath($property)
                ->addViolation()
            ;
        }

        if (null === $value->getName()) {
            $this->context->buildViolation('An unexpected error has made our parser fail to find a package name in your repository, if you think this is incorrect please try again')
                ->atPath($property)
                ->addViolation()
            ;
        }
    }
}
