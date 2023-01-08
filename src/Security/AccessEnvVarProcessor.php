<?php

declare(strict_types=1);

namespace Packeton\Security;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

class AccessEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $isAnonymousAccess = $getEnv("bool:".$name);

        return $isAnonymousAccess ? 'IS_AUTHENTICATED_ANONYMOUSLY' : 'ROLE_USER';
    }

    public static function getProvidedTypes(): array
    {
        return [
            'acl_level' => 'string'
        ];
    }
}
