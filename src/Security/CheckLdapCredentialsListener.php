<?php

declare(strict_types=1);

namespace Packeton\Security;

use Packeton\Entity\User;
use Symfony\Component\Ldap\Security\CheckLdapCredentialsListener as SfCheckLdapCredentialsListener;
use Symfony\Component\Ldap\Security\LdapBadge;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Decorate default LdapCredentialsListener. If  LDAP password login is failed,
 * then try to use next default system user CheckCredentialsListener if user was loaded from a database
 */
class CheckLdapCredentialsListener extends SfCheckLdapCredentialsListener
{
    /**
     * {@inheritdoc}
     */
    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();

        try {
            parent::onCheckPassport($event);
        } catch (BadCredentialsException $e) {
            $user = $passport->getUser();

            // Only if user exists in the local database, fallback to CheckCredentialsListener
            if ($user instanceof User && $passport->hasBadge(LdapBadge::class) && $passport->hasBadge(PasswordCredentials::class)) {
                if ($passport->getBadge(PasswordCredentials::class)->isResolved()) {
                    throw new \LogicException('LDAP authentication password verification cannot be completed because something else has already resolved the PasswordCredentials.');
                }

                $ldapBadge = $passport->getBadge(LdapBadge::class);
                $ldapBadge->markResolved();
                return;
            }

            throw $e;
        }
    }
}
