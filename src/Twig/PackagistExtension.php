<?php

namespace Packeton\Twig;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\Group;
use Packeton\Entity\Job;
use Packeton\Entity\Package;
use Packeton\Entity\User;
use Packeton\Form\Model\PackagePermission;
use Packeton\Model\ProviderManager;
use Packeton\Security\JWTUserManager;
use Symfony\Component\Form\FormView;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

class PackagistExtension extends AbstractExtension
{
    public function __construct(
        private readonly ProviderManager $providerManager,
        private readonly ManagerRegistry $registry,
        private readonly JWTUserManager $jwtUserManager
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
            new TwigFunction('get_group_data', [$this, 'getGroupData']),
            new TwigFunction('get_group_acl_form_data', [$this, 'getGroupAclForm']),
            new TwigFunction('get_api_token', [$this, 'getApiToken']),
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

    public function getGroupAclForm(FormView $items)
    {
        $byVendors = [];
        foreach ($items->children as $item) {
            $data = $item->vars['data'] ?? null;
            if ($data instanceof PackagePermission) {
                [$vendor] = \explode('/', $data->getName());
                $byVendors[$vendor][] = $item;
            }
        }

        $grouped = $otherVendors = [];
        \uasort($byVendors, fn($a, $b) => -1 * (\count($a) <=> \count($b)));
        foreach ($byVendors as $vendorName => $children) {
            if (\count($grouped) < 4 && \count($children) > 1) {
                $grouped[$vendorName] = $children;
            } else {
                $otherVendors = \array_merge($otherVendors, $children);
            }
        }

        if ($otherVendors) {
            $grouped['other'] = $otherVendors;
        }
        foreach ($grouped as $vendor => $children) {
            \usort($children, fn($a, $b) => $a->vars['data']->getName() <=> $b->vars['data']->getName());
            $selected = \count(\array_filter($children, fn($a) => $a->vars['data']->getSelected()));
            $grouped[$vendor] = ['items' => $children, 'selected' => $selected];
        }

        return $grouped;
    }

    public function getGroupData(Group|int $group): array
    {
        return $this->registry->getRepository(Group::class)->getGroupsData($group);
    }

    public function getApiToken(UserInterface $user = null, bool $short = true, bool $generate = false): ?string
    {
        if ($user instanceof User) {
            return ($short ? ($user->getUserIdentifier() . ':') : '') . $user->getApiToken();
        }

        if ($generate && $user instanceof UserInterface) {
            try {
                return $this->jwtUserManager->createTokenForUser($user);
            } catch (\Exception) {}
        }

        return null;
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
