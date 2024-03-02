<?php

declare(strict_types=1);

namespace Packeton\Entity;

use Doctrine\ORM\Mapping as ORM;
use Packeton\Repository\OAuthIntegrationRepository;
use Packeton\Util\PacketonUtils;

#[ORM\Entity(repositoryClass: OAuthIntegrationRepository::class)]
#[ORM\Table(name: 'oauth_integration')]
class OAuthIntegration
{
    use SerializedFieldsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $alias = null;

    #[ORM\Column(name: 'access_token', type: 'json', nullable: true)]
    private ?array $accessToken = null;

    #[ORM\Column(name: 'user_identifier', length: 255, nullable: true)]
    private ?string $owner = null;

    #[ORM\Column(name: 'created_at', type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'hook_secret', length: 255)]
    private ?string $hookSecret = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->serializedFields = [];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAlias(): ?string
    {
        return $this->alias;
    }

    public function setAlias(string $alias): self
    {
        $this->alias = $alias;

        return $this;
    }

    public function getAccessToken(): array
    {
        return ($this->accessToken ?: []) + ['tid' => $this->id];
    }

    public function setAccessToken(?array $accessToken): self
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    public function getSerializedFields(): array
    {
        return $this->serializedFields;
    }

    public function setSerializedFields(?array $serializedFields): self
    {
        $this->serializedFields = $serializedFields;

        return $this;
    }

    public function getOwner(): ?string
    {
        return $this->owner;
    }

    public function setOwner(?string $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getHookSecret(): ?string
    {
        return $this->hookSecret;
    }

    public function getHookToken(): ?string
    {
        return 'whk_'.$this->hookSecret;
    }

    public function setHookSecret(string $hookSecret): self
    {
        $this->hookSecret = $hookSecret;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getEnabledOrganizations(): array
    {
        return $this->getSerialized('enabled_org', 'array', []);
    }

    public function setWebhookInfo(string|int $orgs, array $info): self
    {
        $info += ['status' => true, 'error' => null, 'id' => null];
        return $this->setSerialized("web_hook_$orgs", $info);
    }

    public function getWebhookInfo(string|int $orgs): ?array
    {
        $info = $this->getSerialized("web_hook_$orgs", 'array');
        return is_array($info) ? $info + ['status' => null, 'error' => null, 'id' => null] : null;
    }

    public function isConnected(string|int $name): bool
    {
        return in_array((string)$name, $this->getEnabledOrganizations());
    }

    public function setConnected(string|int $name, bool $connected = true): self
    {
        $orgs = $this->getEnabledOrganizations();
        if (false === $connected) {
            $orgs = array_flip($orgs);
            unset($orgs[$name]);
            unset($orgs[(string)$name]);
            $this->setEnabledOrganizations(array_keys($orgs));
        } else {
            $orgs[] = (string) $name;
            $this->setEnabledOrganizations(array_unique($orgs));
        }

        return $this;
    }

    public function setEnabledOrganizations(?array $organizations): self
    {
        $this->setSerialized('enabled_org', $organizations);
        return $this;
    }

    public function setExcludedRepos(?array $repos): self
    {
        $this->setSerialized('excluded_repos', $repos);
        return $this;
    }

    public function getExcludedRepos(): ?array
    {
        return $this->getSerialized('excluded_repos', 'array');
    }

    public function setGlobFilter(?string $globs): self
    {
        return $this->setSerialized('glob_filter', $globs);
    }

    public function getGlobFilter(): ?string
    {
        return $this->getSerialized('glob_filter', 'string');
    }

    public function setEnableSynchronization(?bool $flag): self
    {
        return $this->setSerialized('enable_synchronization', $flag);
    }

    public function isEnableSynchronization(): ?bool
    {
        return $this->getSerialized('enable_synchronization', 'boolean');
    }

    public function setPullRequestReview(?bool $flag): self
    {
        return $this->setSerialized('pull_request_review', $flag);
    }

    public function isPullRequestReview(): ?bool
    {
        return $this->getSerialized('pull_request_review', 'boolean');
    }

    public function isUseForExpressionApi(): ?bool
    {
        return $this->getSerialized('use_for_expr', 'boolean');
    }

    public function setUseForExpressionApi(?bool $value = null): self
    {
        return $this->setSerialized('use_for_expr', $value);
    }

    public function getClonePreference(): ?string
    {
        return $this->getSerialized('clone_preference', 'string');
    }

    public function setClonePreference(?string $flag): self
    {
        return $this->setSerialized('clone_preference', $flag);
    }

    public function getInstallationId(): ?int
    {
        return $this->getSerialized('installation_id', 'int');
    }

    public function setInstallationId(null|string|int $id = null): self
    {
        return $this->setSerialized('installation_id', $id ? (int) $id : null);
    }

    public function filteredRepos(array $repos, bool $short = false): array
    {
        $listOf = array_column($repos, 'name');
        $filtered = PacketonUtils::matchGlobAll($listOf, $this->getGlobFilter(), $this->getExcludedRepos());
        $filtered = array_flip($filtered);

        foreach ($repos as $i => $repo) {
            if (!isset($filtered[$repo['name']])) {
                unset($repos[$i]);
            }
        }

        $repos = array_values($repos);
        if ($short) {
            $repos = array_map(fn($r) => ['id' => $r['ext_ref'], 'text' => $r['name']], $repos);
        }
        return $repos;
    }

    public function __toString(): string
    {
        $label = $this->label ?? trim($this->owner . ' ' . $this->createdAt->format('Y-m-d'));
        return ucfirst($this->alias) . " ($label)";
    }
}
