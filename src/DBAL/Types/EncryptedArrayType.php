<?php

declare(strict_types=1);

namespace Packeton\DBAL\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\TextType;

class EncryptedArrayType extends TextType
{
    use EncryptedTypeTrait {
        convertToDatabaseValue as traitConvertToDatabaseValue;
        convertToPHPValue as traitConvertToPHPValue;
    }

    /**
     * {@inheritdoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform)
    {
        return $value ? $this->traitConvertToDatabaseValue(json_encode($value), $platform) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform)
    {
        if ($value = $this->traitConvertToPHPValue($value, $platform)) {
            return json_decode($value, true) ?: null;
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'encrypted_array';
    }
}
