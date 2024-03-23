<?php

declare(strict_types=1);

namespace Packeton\Security\Token;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\OAuthIntegration;
use Packeton\Model\AutoHookUser;
use Packeton\Model\PatUserScores;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserInterface;

class IntegrationTokenChecker implements TokenCheckerInterface, PatTokenCheckerInterface
{
    public function __construct(
        private readonly ManagerRegistry $registry,
    ) {
    }

    public const TOKEN_PREFIX = 'whk_';

    /**
     * {@inheritdoc}
     */
    public function support(string $username, string $token): bool
    {
        return $username === 'token' && str_starts_with($token, self::TOKEN_PREFIX);
    }

    /**
     * {@inheritdoc}
     */
    public function loadUserByToken(string $username, string $token, Request $request, callable $userLoader): UserInterface
    {
        $this->checkAccess($request);

        $token = substr($token, strlen(self::TOKEN_PREFIX));

        $apiToken = $token ? $this->registry->getRepository(OAuthIntegration::class)->findOneBy(['hookSecret' => $token]) : null;
        if ($apiToken instanceof OAuthIntegration) {
            return new AutoHookUser($apiToken->getId());
        }

        throw new BadCredentialsException('Bad credentials');
    }

    /**
     * {@inheritdoc}
     */
    public function checkAccess(Request $request, ?UserInterface $user = null): void
    {
        $route = $request->attributes->get('_route');
        if ($route !== 'api_integration_postreceive' && !PatUserScores::isAllowed('webhooks', $route)) {
            throw new CustomUserMessageAccountStatusException('Integration access token allowed only for web hooks route "api_integration_postreceive"');
        }
    }
}
