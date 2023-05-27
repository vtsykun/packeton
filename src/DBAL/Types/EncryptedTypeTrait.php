<?php

namespace Packeton\DBAL\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Packeton\DBAL\OpensslCrypter;

trait EncryptedTypeTrait
{
    /** @var OpensslCrypter */
    protected static $crypter;

    public static function setCrypter(OpensslCrypter $crypter): void
    {
        static::$crypter = $crypter;
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        return $value ? static::$crypter->encryptData($value) : null;
    }

    /**
     * {@inheritdoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): mixed
    {
        if (!$value = parent::convertToPHPValue($value, $platform)) {
            return null;
        }

        return static::$crypter->isEncryptData($value) ? static::$crypter->decryptData($value) : $value;
    }

    /** {@inheritdoc} */
    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
