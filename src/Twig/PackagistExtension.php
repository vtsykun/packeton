<?php

namespace Packeton\Twig;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Job;
use Packeton\Entity\Package;
use Packeton\Model\ProviderManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

class PackagistExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProviderManager $providerManager,
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function getTests()
    {
        return array(
            new TwigTest('existing_package', [$this, 'packageExistsTest']),
            new TwigTest('existing_provider', [$this, 'providerExistsTest']),
            new TwigTest('numeric', [$this, 'numericTest']),
        );
    }

    public function getFunctions()
    {
        return [
            new TwigFunction('package_job_result', [$this, 'getLatestJobResult']),
        ];
    }

    public function getFilters()
    {
        return [
            new TwigFilter('prettify_source_reference', [$this, 'prettifySourceReference']),
            new TwigFilter('gravatar_hash', [$this, 'generateGravatarHash']),
            new TwigFilter('truncate', [$this, 'truncate']),
        ];
    }

    public function getLatestJobResult($package): ?Job
    {
        if ($package instanceof Package) {
            $package = $package->getId();
        }

        return $this->registry->getRepository(Job::class)->findLastJobByType('package:updates', $package);
    }

    public function truncate($string, $length)
    {
        if (empty($string)) {
            return "";
        }

        return mb_strlen($string) > $length ? mb_substr($string, 0, $length) . '...' : $string;
    }

    public function numericTest($val)
    {
        return ctype_digit((string) $val);
    }

    public function packageExistsTest($package)
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $package)) {
            return false;
        }

        return $this->providerManager->packageExists($package);
    }

    public function providerExistsTest($package)
    {
        return $this->providerManager->packageIsProvided($package);
    }

    public function prettifySourceReference($sourceReference)
    {
        if (preg_match('/^[a-f0-9]{40}$/', $sourceReference)) {
            return substr($sourceReference, 0, 7);
        }

        return $sourceReference;
    }

    public function generateGravatarHash($email)
    {
        return md5(strtolower($email));
    }
}
