<?php

declare(strict_types=1);

namespace Packeton\Controller\Api;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

trait ApiControllerTrait
{
    protected function getJsonPayload(Request $request): array
    {
        if (empty($content = $request->getContent())) {
            return [];
        }

        if (!\is_array($payload = \json_decode($content, true))) {
            throw new BadRequestHttpException();
        }

        return $payload;
    }
}
