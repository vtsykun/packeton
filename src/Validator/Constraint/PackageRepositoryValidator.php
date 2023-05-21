<?php

declare(strict_types=1);

namespace Packeton\Validator\Constraint;

use Packeton\Composer\Repository\ArtifactRepository;
use Packeton\Entity\Package;
use Packeton\Package\RepTypes;
use Packeton\Util\PacketonUtils;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class PackageRepositoryValidator extends ConstraintValidator
{
    public function __construct(protected ?array $artifactPaths)
    {
    }

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
            throw new UnexpectedTypeException($value, Package::class);
        }

        match ($value->getRepoType()) {
            RepTypes::ARTIFACT => $this->validateArtifactPackage($value),
            default => $this->validateVcsPackage($value),
        };
    }

    protected function validateArtifactPackage(Package $value): void
    {
        $property = 'repository';
        if ($path = $value->getRepositoryPath()) {
            if (null === PacketonUtils::filterAllowedPaths($path, $this->artifactPaths ?: [])) {
                $this->context->buildViolation(sprintf('The path is not allowed: "%s"', $path))
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }
        }

        if (empty($value->getArchives()) && empty($value->getRepositoryPath())) {
            $this->context->addViolation('You must select at least path or archives choice.');
            return;
        }
        if (null === $value->getName() && null === $value->driverError) {
            $this->context->addViolation('Unable to fetch composer.json from archives');
            return;
        }

        if ($value->driverError) {
            $this->context->addViolation($value->driverError);
        }
        if (true === $value->artifactDriver) {
            return;
        }

        if ($value->artifactDriver instanceof ArtifactRepository) {
            try {
                $packages = $value->artifactDriver->getPackages();
                $packages = array_unique(array_map(fn($p) => $p->getName(), $packages));
                if (count($packages) > 1) {
                    $this->context->addViolation('Different archives contains multiply packages: ' . json_encode(array_values($packages), 64));
                }

                $this->validatePackageName(reset($packages));
            } catch (\Throwable $e) {
                if ($e->getMessage() !== $value->driverError) {
                    $this->context->addViolation($e->getMessage());
                }
            }
        } else  {
            $this->context->buildViolation('No valid/supported repository was found at the given PATH')
                ->atPath($property)
                ->addViolation()
            ;
        }
    }

    protected function validateVcsPackage(Package $value): void
    {
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
                $this->context->buildViolation('URLs with user:pass@host are not supported, use credentials instead')
                    ->atPath($property)
                    ->addViolation()
                ;
            } elseif (is_string($value->driverError)) {
                $this->context->buildViolation('Uncaught Exception: '.htmlentities($value->driverError, ENT_COMPAT, 'utf-8'))
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
            $information = $driver->getComposerInformation($rootId = $driver->getRootIdentifier());
            if (false === $information) {
                $this->context->buildViolation('No composer.json was found in the '.$driver->getRootIdentifier().' branch.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            if ($value->getRepoType() === RepTypes::MONO_REPO) {
                try {
                    $list = $driver->getRepoTree($rootId);
                    if (!$list = PacketonUtils::matchGlob($list, $value->getGlob(), $value->getExcludedGlob())) {
                        $this->context->buildViolation("No any composer.json found for git tree by glob expr {$value->getGlob()}")->atPath($property)->addViolation();
                    } else {
                        $value->driverDebugInfo = "Found/s composer.json:\n" . implode("\n", $list);
                    }
                } catch (\Throwable $e) {
                    $this->context->buildViolation($e->getMessage())->atPath($property)->addViolation();
                }
            }

            $this->validatePackageName($information['name'] ?? null);
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

    protected function validatePackageName($name): void
    {
        $property = 'repository';
        if (empty($name)) {
            $this->context->buildViolation('The package name was not found in the composer.json, make sure there is a name present.')
                ->atPath($property)
                ->addViolation()
            ;
            return;
        }

        if (!preg_match('{^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$}i', $name)) {
            $this->context->buildViolation('The package name '.htmlentities($name, ENT_COMPAT, 'utf-8').' is invalid, it should have a vendor name, a forward slash, and a package name. The vendor and package name can be words separated by -, . or _. The complete name should match "[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*".')
                ->atPath($property)
                ->addViolation()
            ;
            return;
        }

        $reservedNames = ['nul', 'con', 'prn', 'aux', 'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9', 'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9'];
        $bits = explode('/', strtolower($name));
        if (in_array($bits[0], $reservedNames, true) || in_array($bits[1], $reservedNames, true)) {
            $this->context->buildViolation('The package name '.htmlentities($name, ENT_COMPAT, 'utf-8').' is reserved, package and vendor names can not match any of: '.implode(', ', $reservedNames).'.')
                ->atPath($property)
                ->addViolation()
            ;
            return;
        }

        if (preg_match('{\.json$}', $name)) {
            $this->context->buildViolation('The package name '.htmlentities($name, ENT_COMPAT, 'utf-8').' is invalid, package names can not end in .json, consider renaming it or perhaps using a -json suffix instead.')
                ->atPath($property)
                ->addViolation()
            ;
            return;
        }

        if (preg_match('{[A-Z]}', $name)) {
            $suggestName = preg_replace('{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}', '\\1\\3-\\2\\4', $name);
            $suggestName = strtolower($suggestName);

            $this->context->buildViolation('The package name '.htmlentities($name, ENT_COMPAT, 'utf-8').' is invalid, it should not contain uppercase characters. We suggest using '.$suggestName.' instead.')
                ->atPath($property)
                ->addViolation()
            ;
        }
    }
}
