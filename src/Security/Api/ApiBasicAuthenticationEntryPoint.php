<?php

namespace Packeton\Security\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class ApiBasicAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    /**
     * {@inheritdoc}
     */
    public function start(Request $request, AuthenticationException $authException = null)
    {
        $message = $authException->getMessage();
        if ($authException instanceof AuthenticationCredentialsNotFoundException) {
            $message = 'Authorization Required';
        }

        return new JsonResponse(['status' => 'error', 'message' => $message], 401);
    }
}
