<?php

declare(strict_types=1);

namespace Packeton\Security\Api;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Http\Firewall\AbstractListener;
use Symfony\Component\Security\Http\Firewall\FirewallListenerInterface;

/**
 * Mixed Authenticator. Used to allow call api method from session context
 * - if used Composer API access token - select ApiTokenAuthenticator
 * - if exists session - select main context ContextListener.
 *
 * This is simplified user access to debug metadata and reuse API
 */
#[Exclude]
class ApiContextListenerDecorator extends AbstractListener
{
    public function __construct(private readonly FirewallListenerInterface $listener)
    {
    }

    public function supports(Request $request): ?bool
    {
        // Allow only GET request. it's protected by CORS rules +same-site strict rule.
        return $request->getMethod() === 'GET';
    }

    public function authenticate(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $request->attributes->set('_stateless', false);

        $this->listener->authenticate($event);

        $request->attributes->set('_security_firewall_run', '__unset__');
    }
}
