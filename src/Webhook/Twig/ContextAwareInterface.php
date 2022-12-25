<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Webhook\Twig;

interface ContextAwareInterface
{
    /**
     * @param WebhookContext|null $context
     */
    public function setContext(WebhookContext $context = null): void;
}
