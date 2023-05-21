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

    public function getExcludedGlob(): ?string
    {
        return $this->serializedData['excludedGlob'] ?? null;
    }

    public function setExcludedGlob(?string $glob): void
    {
        $this->setSerializedField('excludedGlob', $glob);
    }

    public function isSkipNotModifyTag(): ?bool
    {
        return (bool)($this->serializedData['skip_empty_tag'] ?? null);
    }

    public function setSkipNotModifyTag(?bool $value): void
    {
        $this->setSerializedField('skip_empty_tag', $value);
    }

    public function getArchives(): ?array
    {
        return $this->serializedData['archives'] ?? null;
    }

    public function setArchives(?array $archives): void
    {
        $prev = $this->getArchives() ?: [];
        $new = $archives ?: [];
        sort($new);
        sort($prev);

        if ($new !== $prev) {
            $this->artifactDriver = $this->driverError = null;
        }

        $this->setSerializedField('archives', $archives);
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
