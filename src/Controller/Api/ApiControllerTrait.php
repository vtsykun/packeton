<?php

declare(strict_types=1);

namespace Packeton\Controller\Api;

use Packeton\Controller\ControllerTrait;
use Seld\JsonLint\JsonParser;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

trait ApiControllerTrait
{
    use ControllerTrait;

    protected function getJsonPayload(Request $request): array
    {
        if (empty($content = $request->getContent())) {
            return [];
        }

        if (!\is_array($payload = \json_decode($content, true))) {
            $parser = new JsonParser();
            $result = $parser->lint($content);
            throw new BadRequestHttpException("JSON is not a valid. " . $result->getMessage());
        }

        return $payload;
    }


    protected function badRequest(mixed $data = []): JsonResponse
    {
        if ($data instanceof FormInterface) {
            $data = $this->getErrors($data);
        }

        return $this->json($data, Response::HTTP_BAD_REQUEST);
    }

    protected function getErrors(FormInterface $form): array
    {
        $errors = [];
        foreach ($form as $child) {
            /** @var FormError $error */
            foreach ($child->getErrors(true) as $error) {
                $errors[$child->getName()] = $error->getMessage();
            }
        }

        return $errors;
    }
}
