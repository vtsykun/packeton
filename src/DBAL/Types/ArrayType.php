<?php

declare(strict_types=1);

namespace Packeton\DBAL\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

/**
 * Backward compatibility level for DBAL 4.0 and 3.0
 */
class ArrayType extends Type
{
    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform)
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform)
    {
        return null === $value ? null : json_encode($value);
    }

    /**
     * {@inheritDoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform)
    {
        if ($value === null) {
            return null;
        }

        $value = is_resource($value) ? stream_get_contents($value) : $value;
        if (!is_string($value)) {
            return [];
        }

        if (!str_starts_with($value, 'a:')) {
            $result = json_decode($value, true);
            return is_array($result) ? $result : [];
        }

        set_error_handler(function (int $code, string $message): bool {
            if ($code === E_DEPRECATED || $code === E_USER_DEPRECATED) {
                return false;
            }

            throw ConversionException::conversionFailedUnserialization($this->getName(), $message);
        });

        try {
            $result = unserialize($value, ['allowed_classes' => false]);
        } finally {
            restore_error_handler();
        }

        return is_array($result) ? $result : [];
    }

    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return 'array';
    }

    /**
     * {@inheritDoc}
     */
    public function requiresSQLCommentHint(AbstractPlatform $platform)
    {
        return true;
    }
}
