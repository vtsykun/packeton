<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Webhook\Twig;

use Twig\Environment;
use Twig\Extension\ExtensionInterface;
use Twig\Loader\ArrayLoader;

class PayloadRenderer extends Environment
{
    private $extensions = [];

    public function __construct($options = [])
    {
        $loader = new ArrayLoader();
        parent::__construct($loader, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function setContext(WebhookContext $context = null): void
    {
        foreach ($this->extensions as $extension) {
            if ($extension instanceof ContextAwareInterface) {
                $extension->setContext($context);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addExtension(ExtensionInterface $extension)
    {
        $this->extensions[] = $extension;
        parent::addExtension($extension);
    }
}
