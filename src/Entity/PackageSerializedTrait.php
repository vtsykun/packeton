<?php

declare(strict_types=1);

namespace Packeton\Entity;

use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\Constraint;

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

    public function getWebhookInfo(): ?array
    {
        return $this->serializedData['webhook_info'] ?? null;
    }

    public function setWebhookInfo(?array $info): void
    {
        $this->setSerializedField('webhook_info', $info);
    }

    public function isPullRequestReview(): bool
    {
        return (bool)($this->config['pull_request_review'] ?? false);
    }

    public function setPullRequestReview(?bool $value = null): void
    {
        $this->setSerializedField('pull_request_review', $value);
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

    public function getAllArchives(): ?array
    {
        $archives =  $this->serializedData['archives'] ?? [];
        foreach ($this->getCustomVersions() as $version) {
            if (isset($version['dist'])) {
                $archives[] = $version['dist'];
            }
        }

        return $archives;
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

    public function setUpdateFlags(int $flags): void
    {
        $this->serializedData['update_flags'] = $flags;
    }

    public function getUpdateFlags(): int
    {
        return $this->serializedData['update_flags'] ?? 0;
    }

    public function resetUpdateFlags(): int
    {
        $flags = $this->getUpdateFlags();
        unset($this->serializedData['update_flags']);

        return $flags;
    }

    public function getRequirePatches(): array
    {
        $patches = $this->serializedData['req_patch'] ?? [];
        return is_array($patches) ? $patches : [];
    }

    public function setRequirePatches(?array $patches): void
    {
        $patches = $patches ?: null;
        $this->setSerializedField('req_patch', $patches);
    }

    public function addRequirePatch(string $version, ?array $patch): void
    {
        $patches = $this->getRequirePatches();
        if (empty($patch)) {
            unset($patches[$version]);
        } else {
            $patches[$version] = $patch;
        }

        $this->setRequirePatches($patches);
    }

    public function hasRequirePatch(): bool
    {
        return (bool) $this->getRequirePatches();
    }

    public function findRequirePatch(string $normalizedVersion, string &$matchKey = null): ?array
    {
        $parser = new VersionParser();
        foreach ($this->getRequirePatches() as $constraintStr => $patch) {
            try {
                $constraint = $parser->parseConstraints($constraintStr);
                $match = new Constraint('==', $normalizedVersion);
                if ($constraint->matches($match) === true) {
                    $matchKey = $constraintStr;
                    return $patch;
                }
            } catch (\Exception $e) {
            }
        }

        return null;
    }

    public function getSecurityAudit(): array
    {
        return $this->serializedData['security_audit'] ?? [];
    }

    public function setSecurityAudit(array $audit): void
    {
        if (false === ($audit['enabled'] ?? false) && count($audit) === 1) {
            $audit = null;
        }

        $this->setSerializedField('security_audit', $audit);
    }

    public function getCustomVersions(): array
    {
        return $this->serializedData['custom_versions'] ?? [];
    }

    public function setCustomVersions($versions): void
    {
        $this->customDriver = null;

        $versions = $versions ? array_values($versions) : null;

        $this->setSerializedField('custom_versions', $versions);
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
