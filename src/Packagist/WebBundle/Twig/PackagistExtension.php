<?php

namespace Packagist\WebBundle\Twig;

use Symfony\Component\DependencyInjection\ContainerInterface;

class PackagistExtension extends \Twig_Extension
{
    /**
     * @var ContainerInterface
     */
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function getTests()
    {
        return array(
            new \Twig_SimpleTest('existing_package', [$this, 'packageExistsTest']),
            new \Twig_SimpleTest('existing_provider', [$this, 'providerExistsTest']),
            new \Twig_SimpleTest('numeric', [$this, 'numericTest']),
        );
    }

    public function getFilters()
    {
        return array(
            new \Twig_SimpleFilter('prettify_source_reference', [$this, 'prettifySourceReference']),
            new \Twig_SimpleFilter('gravatar_hash', [$this, 'generateGravatarHash'])
        );
    }

    public function getName()
    {
        return 'packagist';
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

        return $this->getProviderManager()->packageExists($package);
    }

    public function providerExistsTest($package)
    {
        return $this->getProviderManager()->packageIsProvided($package);
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

    /**
     * @return \Packagist\WebBundle\Model\ProviderManager
     */
    private function getProviderManager()
    {
        return $this->container->get('packagist.provider_manager');
    }
}
