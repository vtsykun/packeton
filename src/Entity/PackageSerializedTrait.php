<?php

declare(strict_types=1);

namespace Packeton\Entity;

use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\Constraint;

trait PackageSerializedTrait
{
    use SerializedFieldsTrait;

    public function getSubDirectory(): ?string
    {
        return $this->serializedFields['subDirectory'] ?? null;
    }

    public function setSubDirectory(?string $subDir): void
    {
        $this->setSerialized('subDirectory', $subDir);
    }

    public function getGlob(): ?string
    {
        return $this->serializedFields['glob'] ?? null;
    }

    public function setGlob(?string $glob): void
    {
        $this->setSerialized('glob', $glob);
    }

    public function getExcludedGlob(): ?string
    {
        return $this->serializedFields['excludedGlob'] ?? null;
    }

    public function setExcludedGlob(?string $glob): void
    {
        $this->setSerialized('excludedGlob', $glob);
    }

    public function getWebhookInfo(): ?array
    {
        return $this->serializedFields['webhook_info'] ?? null;
    }

    public function setWebhookInfo(?array $info): void
    {
        $this->setSerialized('webhook_info', $info);
    }

    public function isPullRequestReview(): bool
    {
        return (bool)($this->config['pull_request_review'] ?? false);
    }

    public function setPullRequestReview(?bool $value = null): void
    {
        $this->setSerialized('pull_request_review', $value);
    }

    public function isSkipNotModifyTag(): ?bool
    {
        return (bool)($this->serializedFields['skip_empty_tag'] ?? null);
    }

    public function setSkipNotModifyTag(?bool $value): void
    {
        $this->setSerialized('skip_empty_tag', $value);
    }

    public function getArchives(): ?array
    {
        return $this->serializedFields['archives'] ?? null;
    }

    public function getAllArchives(): ?array
    {
        $archives =  $this->serializedFields['archives'] ?? [];
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

        $this->setSerialized('archives', $archives);
    }

    public function setUpdateFlags(int $flags): void
    {
        $this->setSerialized('update_flags', $flags);
    }

    public function getUpdateFlags(): int
    {
        return $this->getSerialized('update_flags', 'int', 0);
    }

    public function resetUpdateFlags(): int
    {
        $flags = $this->getUpdateFlags();
        unset($this->serializedFields['update_flags']);

        return $flags;
    }

    public function getRequirePatches(): array
    {
        return $this->getSerialized('req_patch', 'array', []);
    }

    public function setRequirePatches(?array $patches): void
    {
        $this->setSerialized('req_patch', $patches ?: null);
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
        return $this->serializedFields['security_audit'] ?? [];
    }

    public function setSecurityAudit(array $audit): void
    {
        if (false === ($audit['enabled'] ?? false) && count($audit) === 1) {
            $audit = null;
        }

        $this->setSerialized('security_audit', $audit);
    }

    public function hasSecurityIssue(): bool
    {
        $audit = $this->getSecurityAudit();
        return ($audit['enabled'] ?? false) && ($audit['advisories'] ?? null);
    }

    public function getCustomVersions(): array
    {
        return $this->getSerialized('custom_versions', 'array', []);
    }

    public function setCustomVersions($versions): void
    {
        $this->customDriver = null;

        $versions = $versions ? array_values($versions) : null;

        $this->setSerialized('custom_versions', $versions);
    }

    public function isDisabledUpdate(): bool
    {
        return (bool) ($this->serializedFields['disabled_update'] ?? false);
    }

    public function setDisabledUpdate(?bool $flag): void
    {
        $this->setSerialized('disabled_update', $flag);
    }
}
