<?php

declare(strict_types=1);

namespace Packeton\Serializer;

use Packeton\Exception\DebugHttpExceptionInterface;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;
use Symfony\Component\Serializer\Normalizer\CacheableSupportsMethodInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class ErrorNormalizer implements NormalizerInterface
{
    private $defaultContext = [
        'type' => 'https://tools.ietf.org/html/rfc2616#section-10',
        'title' => 'An error occurred',
    ];

    public function __construct(protected bool $debug = false)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function normalize($object, string $format = null, array $context = []): array
    {
        if (!$object instanceof FlattenException) {
            throw new InvalidArgumentException(sprintf('The object must implement "%s".', FlattenException::class));
        }

        $previous = $context['exception'] ?? null;
        $context += $this->defaultContext;
        $debug = ($context['debug'] ?? false);
        $showDetail = $debug || $previous instanceof DebugHttpExceptionInterface ||
            $previous?->getPrevious() instanceof DebugHttpExceptionInterface;

        $data = [
            'status' => $context['status'] ?? $object->getStatusCode(),
            'detail' => $showDetail ? $object->getMessage() : $object->getStatusText(),
        ];

        $data += [
            'title' => Response::$statusTexts[$data['status']] ?? $context['title'],
            'type' => $context['type'],
        ];

        if ($debug) {
            $data['class'] = $object->getClass();
            $data['trace'] = $object->getTrace();
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, string $format = null, array $context = []): bool
    {
        return $data instanceof FlattenException;
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            FlattenException::class => true,
        ];
    }
}
