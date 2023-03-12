<?php

declare(strict_types=1);

namespace Packeton\Entity;

trait PackageSerializedTrait
{
    public function getSubDirectory(): ?string
    {
        return $this->serializedData['subDirectory'] ?? null;
    }

    public function setSubDirectory(?string $subDir): void
    {
        $this->setSerializedField('subDirectory', $subDir);
    }

    public function getGlob(): ?string
    {
        return $this->serializedData['glob'] ?? null;
    }

    public function setGlob(?string $glob): void
    {
        $this->setSerializedField('glob', $glob);
    }

    public function isSkipNotModifyTag(): ?bool
    {
        return $this->serializedData['skip_empty_tag'] ?? null;
    }

    public function setSkipNotModifyTag($value): void
    {
        $this->setSerializedField('skip_empty_tag', $value);
    }

    protected function setSerializedField(string $field, mixed $value): void
    {
        if (null === $value) {
            unset($this->serializedData[$field]);
        } else {
            $this->serializedData[$field] = $value;
        }
    }
}
