<?php

namespace Packagist\WebBundle\Webhook\Twig;

use Doctrine\Persistence\ManagerRegistry;
use Packagist\WebBundle\Util\ChangelogUtils;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class WebhookExtension extends AbstractExtension
{
    private $registry;
    private $changelogUtils;

    public function __construct(
        ManagerRegistry $registry,
        ChangelogUtils $changelogUtils
    ) {
        $this->registry = $registry;
        $this->changelogUtils = $changelogUtils;
    }

    /**
     * {@inheritdoc}
     */
    public function getFunctions()
    {
        $functions = [];
        $reflect = new \ReflectionClass(__CLASS__);
        foreach ($reflect->getMethods() as $method) {
            if ($method->isPublic() && strpos($method->getName(), 'hook_function_') === 0) {
                $functions[] = new TwigFunction(substr($method->getName(), 14), [$this, $method->getName()]);
            }
        }

        return $functions;
    }

    public function hook_function_get_changelog()
    {
    }

    public function hook_function_interrupt($reason = null)
    {
        throw new InterruptException('This request was interrupt by user from twig code. ' . $reason);
    }
}
