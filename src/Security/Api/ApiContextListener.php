<?php

declare(strict_types=1);

namespace Packeton\Security\Api;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Http\Firewall\ContextListener;

/**
 * Mixed Authenticator. Used to allow call api method from session context
 * - if used Composer API access token - select ApiTokenAuthenticator
 * - if exists session - select main context ContextListener
 */
class ApiContextListener extends ContextListener
{
    /**
     * {@inheritdoc}
     */
    public function supports(Request $request): ?bool
    {
        return $request->getMethod() === 'GET';
    }

    /**
     * {@inheritdoc}
     */
    public function authenticate(RequestEvent $event)
    {
        parent::authenticate($event);

        $request = $event->getRequest();

        // to prevent create a session via API token
        $request->attributes->set('_security_firewall_run', '__unset__');
    }
}
