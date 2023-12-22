<?php

declare(strict_types=1);

namespace Packeton\Entity;

use Doctrine\ORM\Mapping as ORM;

trait SerializedFieldsTrait
{
    #[ORM\Column(name: 'serialized_fields', type: 'json', nullable: true)]
    private ?array $serializedFields = null;

    public function getSerialized(string $field, ?string $type = null, mixed $default = null): int|null|array|string|bool|float
    {
        $value = $this->serializedFields[$field] ?? $default;
        static $aliases = [
            'int' => 'integer',
            'bool' => 'boolean'
        ];

        return null === $type || \gettype($value) === ($aliases[$type] ?? $type) ? $value : $default;
    }

    public function setSerialized(string $field, mixed $value): static
    {
        if (null === $value) {
            unset($this->serializedFields[$field]);
        } else {
            $this->serializedFields[$field] = $value;
        }

        return $this;
    }
}
