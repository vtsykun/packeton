<?php

declare(strict_types=1);

namespace Packeton\Webhook\Twig;

use Okvpn\Expression\TwigLanguage;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

class PayloadRenderer extends TwigLanguage
{
    public function setContext(WebhookContext $context = null): void
    {
        foreach ($this->extensions as $extension) {
            if ($extension instanceof ContextAwareInterface) {
                $extension->setContext($context);
            }
        }
    }

    public function setLogger(LoggerInterface $logger = null): void
    {
        foreach ($this->extensions as $extension) {
            if (null !== $logger && $extension instanceof LoggerAwareInterface) {
                $extension->setLogger($logger);
            }
        }
    }
}
