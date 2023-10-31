<?php

declare(strict_types=1);

namespace Packeton\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

class JsonDataTransformer implements DataTransformerInterface
{
    /**
     * {@inheritdoc}
     */
    public function transform($data): ?string
    {
        if (empty($data)) {
            return null;
        }

        if (is_array($data)) {
            return json_encode($data, 448);
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function reverseTransform(mixed $data): ?array
    {
        if (empty($data) || !is_string($data)) {
            return null;
        }

        $value = @json_decode($data, true);
        if (!is_array($value) && json_last_error()) {
            throw new TransformationFailedException('JSON decoding error: ' . json_last_error_msg());
        }

        return is_array($value) ? $value : null;
    }
}
