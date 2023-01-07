<?php

declare(strict_types=1);

namespace Packeton\DBAL\Types;

use Doctrine\DBAL\Types\TextType;

class EncryptedTextType extends TextType
{
    use EncryptedTypeTrait;

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'encrypted_text';
    }
}
