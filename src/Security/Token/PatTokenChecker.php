<?php

declare(strict_types=1);

namespace Packeton\Security\Token;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\ApiToken;
use Packeton\Entity\User;
use Packeton\Model\PatTokenUser;
use Packeton\Model\PatUserScores;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CredentialsExpiredException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserInterface;

class PatTokenChecker implements TokenCheckerInterface, PatTokenCheckerInterface
{
    public function __construct(
        private readonly FastTokenCache $cache,
        private readonly ManagerRegistry $registry,
        private readonly PatTokenManager $patTokenManager,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function support(string $username, string $token): bool
    {
        return str_starts_with($token, ApiToken::PREFIX) && strlen($token) >= 32;
    }

    /**
     * {@inheritdoc}
     */
    public function loadUserByToken(string $username, string $token, callable $userLoader, Request $request = null): UserInterface
    {
        $token = substr($token, strlen(ApiToken::PREFIX));
        if ($user = $this->cache->hit($username, $token)) {
            return $user;
        }

        $apiToken = $this->registry->getRepository(ApiToken::class)->findOneBy(['apiToken' => $token]);
        if (null === $apiToken || $apiToken->getApiToken() !== $token) {
            throw new BadCredentialsException('Bad credentials');
        }
        if ($apiToken->getExpireAt() && $apiToken->getExpireAt()->getTimestamp() < time()) {
            throw new CredentialsExpiredException('Api token is expire');
        }

        $identifier = $apiToken->getOwner() ? $apiToken->getOwner()->getUserIdentifier() : $apiToken->getUserIdentifier();
        $loadedUser = $userLoader($identifier);

        if (!$loadedUser instanceof UserInterface || ($loadedUser->getUserIdentifier() !== $username && $username !== 'token')) {
            throw new BadCredentialsException('Bad credentials');
        }

        $groups = $attributes = [];
        $attributes['scores'] = $apiToken->getScores();
        if ($loadedUser instanceof User) {
            $attributes['expired_updates'] = $loadedUser->getExpiredUpdatesAt();
            $attributes['sub_repos'] = $loadedUser->getSubRepos();
            $groups = $loadedUser->getAclGroups();
        }

        $user = new PatTokenUser(
            $loadedUser->getUserIdentifier(),
            $loadedUser->getRoles(),
            $groups,
            $attributes
        );

        $this->cache->save($user, $username, $token);
        $this->patTokenManager->setLastUsage($apiToken->getId(), $request ? $this->getRequestInfo($request) : []);
        return $user;
    }

    /**
     * {@inheritdoc}
     */
    public function checkAccess(Request $request, UserInterface $user): void
    {
        $route = $request->attributes->get('_route');
        if (!$user instanceof PatTokenUser) {
            throw new BadCredentialsException('Bad credentials.');
        }

        if (!PatUserScores::isAllowed($user->getScores(), $route)) {
            throw new CustomUserMessageAccountStatusException('This access token does not grant access for this route');
        }
    }

    private function getRequestInfo(Request $request)
    {
        return [
            'ip' => $request->getClientIp(),
            'ua' => $request->headers->get('user-agent'),
        ];
    }
}
