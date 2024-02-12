<?php

declare(strict_types=1);

namespace Packeton\EventListener;

use Packeton\Security\Provider\AuditSessionProvider;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

class AuditLoginListener
{
    public function __construct(private AuditSessionProvider $auditProvider)
    {
    }

    #[AsEventListener('security.interactive_login')]
    public function onUserLogin(InteractiveLoginEvent $event): void
    {
        $token = $event->getAuthenticationToken();
        $request = $event->getRequest();

        if (!$user = $token->getUser()) {
            return;
        }

        $this->auditProvider->logWebLogin($request, $user, $token instanceof RememberMeToken);
    }

    #[AsEventListener]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->getFirewallName() === 'packages') {
            return;
        }

        try {
            if(!$username = $event->getPassport()?->getUser()->getUserIdentifier()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $this->auditProvider->logWebLogin($request, $username, false, $event->getException()->getMessage());
    }
}
