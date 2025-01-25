<?php

declare(strict_types=1);

namespace Packeton\Trait;

trait RequestContextTrait
{
    private function generateUrl(string $path): string
    {
        return rtrim($this->requestContext->getBaseUrl(), '/') . $path;
    }

    private function generateRoute(string $name, ?string $slugName, array $params = []): string
    {
        return $slugName === null ?
            $this->router->generate($name, $params)
            : $this->router->generate($name . '_slug', $params + ['slug' => $slugName]);
    }
}
