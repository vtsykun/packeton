<?php

namespace Packeton\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Persistence\ObjectRepository;
use Packeton\Package\RepTypes;
use Packeton\Repository\PackageRepository;
use Packeton\Repository\VersionRepository;
use Packeton\Util\PacketonUtils;

#[ORM\Entity(repositoryClass: PackageRepository::class)]
#[ORM\Table(name: 'package')]
#[ORM\UniqueConstraint(name: 'package_name_idx', columns: ['name'])]
#[ORM\Index(columns: ['indexedat'], name: 'indexed_idx')]
#[ORM\Index(columns: ['crawledat'], name: 'crawled_idx')]
#[ORM\Index(columns: ['dumpedat'], name: 'dumped_idx')]
class Package
{
    use PackageSerializedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 191)]
    private ?string $name = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $type = null;

    #[ORM\Column(name: 'repo_type', type: 'string', length: 32, nullable: true)]
    private ?string $repoType = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $language = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $readme = null;

    #[ORM\Column(name: 'github_stars', type: 'integer', nullable: true)]
    private $gitHubStars;

    #[ORM\Column(name: 'github_watches', type: 'integer', nullable: true)]
    private $gitHubWatches;

    #[ORM\Column(name: 'github_forks', type: 'integer', nullable: true)]
    private $gitHubForks;

    #[ORM\Column(name: 'github_open_issues', type: 'integer', nullable: true)]
    private $gitHubOpenIssues;

    #[ORM\OneToMany(mappedBy: 'package', targetEntity: Version::class)]
    private $versions;

    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'packages')]
    #[ORM\JoinTable(name: 'maintainers_packages')]
    private $maintainers;

    #[ORM\Column]
    private ?string $repository = null;

    #[ORM\Column(name: 'createdat', type: 'datetime')]
    private $createdAt;

    #[ORM\Column(name: 'updatedat', type: 'datetime', nullable: true)]
    private $updatedAt;

    #[ORM\Column(name: 'crawledat', type: 'datetime', nullable: true)]
    private $crawledAt;

    #[ORM\Column(name: 'indexedat', type: 'datetime', nullable: true)]
    private $indexedAt;

    #[ORM\Column(name: 'dumpedat', type: 'datetime', nullable: true)]
    private $dumpedAt;

    #[ORM\Column(name: 'autoupdated', type: 'boolean')]
    private bool $autoUpdated = false;

    #[ORM\Column(type: 'boolean')]
    private bool $abandoned = false;

    #[ORM\Column(name: 'replacementpackage', type: 'string', nullable: true)]
    private ?string $replacementPackage = null;

    #[ORM\ManyToOne(targetEntity: Package::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Package $parentPackage = null;

    #[ORM\Column(name: 'updatefailurenotified', type: 'boolean', options: ['default' => false])]
    private bool $updateFailureNotified = false;

    #[ORM\ManyToOne(targetEntity: SshCredentials::class)]
    #[ORM\JoinColumn(name: 'credentials_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SshCredentials $credentials = null;

    #[ORM\ManyToOne(targetEntity: OAuthIntegration::class)]
    #[ORM\JoinColumn(name: 'integration_id', referencedColumnName: 'id', nullable: true)]
    private ?OAuthIntegration $integration = null;

    #[ORM\Column(name: 'serialized_data', type: 'json', nullable: true)]
    private ?array $serializedFields = null;

    #[ORM\Column(name: 'external_ref', length: 255, nullable: true)]
    private ?string $externalRef = null;

    #[ORM\Column(name: 'full_visibility', type: 'boolean', nullable: true)]
    private ?bool $fullVisibility = null;

    /**
     * @internal
     * @var \Composer\Repository\Vcs\VcsDriverInterface
     */
    public $vcsDriver = true;

    /**
     * @internal
     * @var \Packeton\Composer\Repository\ArtifactRepository
     */
    public $artifactDriver = true;

    /**
     * @internal
     * @var \Packeton\Composer\Repository\CustomJsonRepository
     */
    public $customDriver = true;

    /**
     * @internal
     */
    public $driverError;

    /**
     * @internal
     */
    public $driverDebugInfo;

    /**
     * @var array lookup table for versions
     */
    private $cachedVersions;

    public function __construct()
    {
        $this->versions = new ArrayCollection();
        $this->createdAt = new \DateTime;
    }

    /**
     * @param VersionRepository|ObjectRepository $versionRepo
     * @return array
     */
    public function toArray(VersionRepository $versionRepo)
    {
        $versions = $versionIds = [];
        $this->versions = $versionRepo->refreshVersions($this->getVersions());
        foreach ($this->getVersions() as $version) {
            $versionIds[] = $version->getId();
        }
        $versionData = $versionRepo->getVersionData($versionIds);
        foreach ($this->getVersions() as $version) {
            /** @var $version Version */
            $versions[$version->getVersion()] = $version->toArray($versionData);
        }
        $maintainers = [];
        foreach ($this->getMaintainers() as $maintainer) {
            /** @var $maintainer User */
            $maintainers[] = $maintainer->toArray();
        }
        $data = [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'time' => $this->getCreatedAt()->format('c'),
            'maintainers' => $maintainers,
            'versions' => $versions,
            'type' => $this->getType(),
            'repository' => $this->getRepository(),
            'github_stars' => $this->getGitHubStars(),
            'github_watchers' => $this->getGitHubWatches(),
            'github_forks' => $this->getGitHubForks(),
            'github_open_issues' => $this->getGitHubOpenIssues(),
            'language' => $this->getLanguage(),
        ];

        if ($this->isAbandoned()) {
            $data['abandoned'] = $this->getReplacementPackage() ?: true;
        }

        return $data;
    }

    /**
     * Get id
     *
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name
     *
     * @param string $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get vendor prefix
     *
     * @return string
     */
    public function getVendor()
    {
        return preg_replace('{/.*$}', '', $this->name);
    }

    /**
     * Get package name without vendor
     *
     * @return string
     */
    public function getPackageName()
    {
        return preg_replace('{^[^/]*/}', '', $this->name);
    }

    /**
     * Set description
     *
     * @param string $description
     */
    public function setDescription($description)
    {
        $this->description = $description;
    }

    /**
     * Get description
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set language
     *
     * @param string $language
     */
    public function setLanguage($language)
    {
        $this->language = $language;
    }

    /**
     * Get language
     *
     * @return string
     */
    public function getLanguage()
    {
        return $this->language;
    }

    /**
     * Set readme
     *
     * @param string $readme
     */
    public function setReadme($readme)
    {
        $this->readme = $readme;
    }

    /**
     * Get readme
     *
     * @return string
     */
    public function getReadme()
    {
        return $this->readme;
    }

    /**
     * @param int $val
     */
    public function setGitHubStars($val)
    {
        $this->gitHubStars = $val;
    }

    /**
     * @return int
     */
    public function getGitHubStars()
    {
        return $this->gitHubStars;
    }

    /**
     * @param int $val
     */
    public function setGitHubWatches($val)
    {
        $this->gitHubWatches = $val;
    }

    /**
     * @return int
     */
    public function getGitHubWatches()
    {
        return $this->gitHubWatches;
    }

    /**
     * @param int $val
     */
    public function setGitHubForks($val)
    {
        $this->gitHubForks = $val;
    }

    /**
     * @return int
     */
    public function getGitHubForks()
    {
        return $this->gitHubForks;
    }

    /**
     * @param int $val
     */
    public function setGitHubOpenIssues($val)
    {
        $this->gitHubOpenIssues = $val;
    }

    /**
     * @return int
     */
    public function getGitHubOpenIssues()
    {
        return $this->gitHubOpenIssues;
    }

    /**
     * Set createdAt
     *
     * @param \DateTime $createdAt
     */
    public function setCreatedAt($createdAt)
    {
        $this->createdAt = $createdAt;
    }

    /**
     * Get createdAt
     *
     * @return \DateTime
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    public function setRepositoryPath(?string $path): void
    {
        $path ??= '_unset';
        if ($this->getRepoType() === RepTypes::ARTIFACT && $this->repository !== $path) {
            $this->artifactDriver = $this->driverError = null;
            $this->repository = $path;
        }

        if ($this->getRepoType() === RepTypes::CUSTOM || $this->getRepoType() === RepTypes::VIRTUAL) {
            $this->customDriver = $this->driverError = null;
            $this->repository = $path;
        }
    }

    public function getRepositoryPath(): ?string
    {
        return $this->repository === '_unset' ? null : $this->repository;
    }

    /**
     * Set repository
     *
     * @param string $repoUrl
     */
    public function setRepository($repoUrl)
    {
        // prevent local filesystem URLs
        if (preg_match('{^(\.|[a-z]:|/)}i', $repoUrl)) {
            return;
        }

        // normalize protocol case
        $repoUrl = preg_replace_callback('{^(https?|git|svn)://}i', fn ($match) => strtolower($match[1]) . '://', $repoUrl);
        if ($this->repository !== $repoUrl) {
            $this->repository = $repoUrl;
            $this->vcsDriver = $this->driverError = null;
        }
    }

    /**
     * Get repository
     *
     * @return string $repository
     */
    public function getRepository()
    {
        return $this->repository;
    }

    /**
     * Get a user-browsable version of the repository URL
     *
     * @return string $repository
     */
    public function getBrowsableRepository()
    {
        $repository = $this->parentPackage ? $this->parentPackage->getRepository() : $this->repository;
        return $repository ? PacketonUtils::getBrowsableRepository($repository) : null;
    }

    public function getRepoConfig(): array
    {
        $repoConfig = [
            'repoType' => $this->repoType ?: 'vcs',
            'subDirectory' => $this->getSubDirectory(),
            'archives' => $this->getArchives(),
            'oauth2' => $this->integration,
            'externalRef' => $this->externalRef,
            'customVersions' => $this->getCustomVersions(),
            'packageName' => $this->name,
        ];

        return array_filter($repoConfig);
    }

    /**
     * @return string|null
     */
    public function getRepoType(): ?string
    {
        return $this->repoType;
    }

    /**
     * @param string|null $repoType
     * @return Package
     */
    public function setRepoType(?string $repoType = null)
    {
        $this->repoType = $repoType;
        return $this;
    }

    /**
     * @return Package|null
     */
    public function getParentPackage(): ?Package
    {
        return $this->parentPackage;
    }

    /**
     * @param Package $parentPackage
     * @return $this
     */
    public function setParentPackage(?Package $package = null)
    {
        $this->parentPackage = $package;
        return $this;
    }

    public function isUpdatable(): bool
    {
        return $this->parentPackage === null;
    }

    public function isSourceEnabled(): bool
    {
        $false = $this->parentPackage !== null
            || ($this->serializedFields['subDirectory'] ?? null)
            || $this->repoType === 'artifact';

        return !$false;
    }

    /**
     * Add versions
     *
     * @param Version $versions
     */
    public function addVersions(Version $versions)
    {
        $this->versions[] = $versions;
    }

    /**
     * Get versions
     *
     * @return \Doctrine\Common\Collections\Collection|Version[]
     */
    public function getVersions()
    {
        return $this->versions;
    }

    public function getVersionByReference(string $reference): ?Version
    {
        $matchedVersions = $this->versions->filter(static fn(Version $v) => $v->getReference() === $reference);

        foreach ($matchedVersions as $matchedVersion) {
            if ($matchedVersion->isDevelopment() === false) {
                return $matchedVersion;
            }
        }

        return $matchedVersions->first() ?: null;
    }

    /**
     * @param string $reference
     * @return Version[]
     */
    public function getAllVersionsByReference(string $reference): array
    {
        return $this->versions->filter(fn(Version $v, $k) => $v->getReference() === $reference)->toArray();
    }

    public function getVersion($normalizedVersion)
    {
        if (null === $this->cachedVersions) {
            $this->cachedVersions = [];
            foreach ($this->getVersions() as $version) {
                $this->cachedVersions[strtolower($version->getNormalizedVersion())] = $version;
            }
        }

        if (isset($this->cachedVersions[strtolower($normalizedVersion)])) {
            return $this->cachedVersions[strtolower($normalizedVersion)];
        }
    }

    /**
     * @return Version|null
     */
    public function getHighest()
    {
        return $this->getVersion('9999999-dev');
    }

    /**
     * Set updatedAt
     *
     * @param \DateTime $updatedAt
     */
    public function setUpdatedAt($updatedAt)
    {
        $this->updatedAt = $updatedAt;
        $this->setUpdateFailureNotified(false);
    }

    /**
     * Get updatedAt
     *
     * @return \DateTime
     */
    public function getUpdatedAt()
    {
        return $this->updatedAt;
    }

    /**
     * Set crawledAt
     *
     * @param \DateTime|null $crawledAt
     */
    public function setCrawledAt($crawledAt)
    {
        $this->crawledAt = $crawledAt;
    }

    /**
     * Get crawledAt
     *
     * @return \DateTime
     */
    public function getCrawledAt()
    {
        return $this->crawledAt;
    }

    /**
     * Set indexedAt
     *
     * @param \DateTime|null $indexedAt
     */
    public function setIndexedAt($indexedAt)
    {
        $this->indexedAt = $indexedAt;
    }

    /**
     * Get indexedAt
     *
     * @return \DateTime
     */
    public function getIndexedAt()
    {
        return $this->indexedAt;
    }

    /**
     * Set dumpedAt
     *
     * @param \DateTime $dumpedAt
     */
    public function setDumpedAt($dumpedAt)
    {
        $this->dumpedAt = $dumpedAt;
    }

    /**
     * Get dumpedAt
     *
     * @return \DateTime
     */
    public function getDumpedAt()
    {
        return $this->dumpedAt;
    }

    /**
     * Add maintainers
     *
     * @param User|object $maintainer
     */
    public function addMaintainer(User $maintainer)
    {
        $this->maintainers[] = $maintainer;
    }

    /**
     * Get maintainers
     *
     * @return \Doctrine\Common\Collections\Collection|User[]
     */
    public function getMaintainers()
    {
        return $this->maintainers;
    }

    /**
     * Set type
     *
     * @param string $type
     */
    public function setType($type)
    {
        $this->type = $type;
    }

    /**
     * Get type
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Set autoUpdated
     *
     * @param Boolean $autoUpdated
     */
    public function setAutoUpdated($autoUpdated)
    {
        $this->autoUpdated = $autoUpdated;
    }

    /**
     * Get autoUpdated
     *
     * @return Boolean
     */
    public function isAutoUpdated()
    {
        return $this->autoUpdated;
    }

    /**
     * Set updateFailureNotified
     *
     * @param Boolean $updateFailureNotified
     */
    public function setUpdateFailureNotified($updateFailureNotified)
    {
        $this->updateFailureNotified = $updateFailureNotified;
    }

    /**
     * Get updateFailureNotified
     *
     * @return Boolean
     */
    public function isUpdateFailureNotified()
    {
        return $this->updateFailureNotified;
    }

    /**
     * @return boolean
     */
    public function isAbandoned()
    {
        return $this->abandoned;
    }

    /**
     * @param boolean $abandoned
     */
    public function setAbandoned($abandoned)
    {
        $this->abandoned = $abandoned;
    }

    /**
     * @return string
     */
    public function getReplacementPackage()
    {
        return $this->replacementPackage;
    }

    /**
     * @param string $replacementPackage
     */
    public function setReplacementPackage($replacementPackage)
    {
        $this->replacementPackage = $replacementPackage;
    }

    /**
     * @return SshCredentials|null
     */
    public function getCredentials()
    {
        return $this->credentials;
    }

    /**
     * @param SshCredentials $credentials
     * @return Package
     */
    public function setCredentials(?SshCredentials $credentials = null)
    {
        $this->credentials = $credentials;
        return $this;
    }

    public function getIntegration(): ?OAuthIntegration
    {
        return $this->integration;
    }

    public function setIntegration(?OAuthIntegration $integration): self
    {
        $this->integration = $integration;

        return $this;
    }

    public function getExternalRef(): ?string
    {
        return $this->externalRef;
    }

    public function setExternalRef(?string $externalRef): self
    {
        $this->externalRef = $externalRef;

        return $this;
    }

    public function isFullVisibility(): ?bool
    {
        return $this->fullVisibility;
    }

    public function setFullVisibility(?bool $fullVisibility): self
    {
        $this->fullVisibility = $fullVisibility;

        return $this;
    }

    public static function sortVersions(Version $a, Version $b)
    {
        $aVersion = $a->getNormalizedVersion();
        $bVersion = $b->getNormalizedVersion();
        $aVersion = preg_replace('{^dev-.*}', '0.0.0-alpha', $aVersion);
        $bVersion = preg_replace('{^dev-.*}', '0.0.0-alpha', $bVersion);

        // equal versions are sorted by date
        if ($aVersion === $bVersion) {
            return $b->getReleasedAt() > $a->getReleasedAt() ? 1 : -1;
        }

        // the rest is sorted by version
        return version_compare($bVersion, $aVersion);
    }
}
