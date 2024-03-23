<?php

declare(strict_types=1);

namespace Packeton\Integrations;

use Packeton\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface LoginInterface extends IntegrationInterface
{
    public const LOGIN_EMAIL = 'email';
    public const LOGIN_USERNAME = 'username';

    /**
     * @param Request|null $request
     * @param array $options
     *
     * @return Response|RedirectResponse
     */
    public function redirectOAuth2Url(?Request $request = null, array $options = []): Response;

    /**
     * @param Request $request
     * @param array $options
     *
     * @return array
     */
    public function getAccessToken(Request $request, array $options = []): array;

    /**
     * @param Request|array $request
     * @param array $options
     * @param array|null $accessToken
     *
     * @return array
     */
    public function fetchUser(Request|array $request, array $options = [], ?array &$accessToken = null): array;

    /**
     * @param array $userData
     * @return User
     */
    public function createUser(array $userData): User;

    /**
     * Login/Register expression check.
     *
     * @param array $context
     * @param string|null $scriptPayload
     * @return mixed
     */
    public function evaluateExpression(array $context = [], ?string $scriptPayload = null): mixed;
}
