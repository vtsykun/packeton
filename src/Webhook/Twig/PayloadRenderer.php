<?php

declare(strict_types=1);

namespace Packeton\Webhook\Twig;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class PayloadRenderer extends Environment implements LoggerAwareInterface
{
    private $init = false;

    public function __construct(private readonly iterable $extensions, $options = [])
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
    public function setLogger(LoggerInterface $logger)
    {
        foreach ($this->extensions as $extension) {
            if ($extension instanceof LoggerAwareInterface) {
                $extension->setLogger($logger);
            }
        }
    }

    public function init()
    {
        if ($this->init === true) {
            return;
        }

        foreach ($this->extensions as $extension) {
            $this->addExtension($extension);
        }

        $this->init = true;
    }
}
