<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packeton\Entity;

use Composer\Package\Version\VersionParser;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Packeton\Composer\MetadataMinifier;
use Packeton\Repository\VersionRepository;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: VersionRepository::class)]
#[ORM\Table(name: 'package_version')]
#[ORM\UniqueConstraint(name: 'pkg_ver_idx', columns: ['package_id', 'normalizedVersion'])]
#[ORM\Index(columns: ['releasedAt'], name: 'release_idx')]
#[ORM\Index(columns: ['development'], name: 'is_devel_idx')]
class Version
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id = null;

    #[ORM\Column]
    #[Assert\NotBlank]
    private $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private $description;

    #[ORM\Column(nullable: true)]
    private $type;

    #[ORM\Column(name: 'targetdir', nullable: true)]
    private $targetDir;

    #[ORM\Column(type: 'array', nullable: true)]
    private ?array $extra = [];

    #[ORM\ManyToMany(targetEntity: 'Packeton\Entity\Tag', inversedBy: 'versions')]
    #[ORM\JoinTable(name: 'version_tag')]
    #[ORM\JoinColumn(name: 'version_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id')]
    private $tags;

    #[ORM\ManyToOne(targetEntity: 'Packeton\Entity\Package', fetch: 'EAGER', inversedBy: 'versions')]
    #[Assert\Type(type: Package::class)]
    private ?Package $package = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Url]
    private $homepage;

    #[ORM\Column]
    #[Assert\NotBlank]
    private $version;

    #[ORM\Column(name: 'normalizedversion', length: 191)]
    #[Assert\NotBlank]
    private $normalizedVersion;

    #[ORM\Column(type: 'boolean')]
    #[Assert\NotBlank]
    private $development;

    #[ORM\Column(type: 'text', nullable: true)]
    private $license;

    #[ORM\ManyToMany(targetEntity: 'Packeton\Entity\Author', inversedBy: 'versions')]
    #[ORM\JoinTable(name: 'version_author')]
    #[ORM\JoinColumn(name: 'version_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'author_id', referencedColumnName: 'id')]
    private $authors;

    #[ORM\OneToMany(mappedBy: 'version', targetEntity: 'Packeton\Entity\RequireLink')]
    private $require;

    #[ORM\OneToMany(mappedBy: 'version', targetEntity: 'Packeton\Entity\ReplaceLink')]
    private $replace;

    #[ORM\OneToMany(mappedBy: 'version', targetEntity: 'Packeton\Entity\ConflictLink')]
    private $conflict;

    #[ORM\OneToMany(mappedBy: 'version', targetEntity: 'Packeton\Entity\ProvideLink')]
    private $provide;

    #[ORM\OneToMany(mappedBy: 'version', targetEntity: 'Packeton\Entity\DevRequireLink')]
    private $devRequire;

    #[ORM\OneToMany(mappedBy: 'version', targetEntity: 'Packeton\Entity\SuggestLink')]
    private $suggest;

    #[ORM\Column(type: 'text', nullable: true)]
    private $source;

    #[ORM\Column(type: 'text', nullable: true)]
    private $dist;

    /**
     * @var string
     */
    public $distNormalized;

    #[ORM\Column(type: 'text', nullable: true)]
    private $autoload;

    #[ORM\Column(type: 'text', nullable: true)]
    private $binaries;

    #[ORM\Column(name: 'includepaths', type: 'text', nullable: true)]
    private $includePaths;

    #[ORM\Column(type: 'text', nullable: true)]
    private $support;

    #[ORM\Column(name: 'createdat', type: 'datetime')]
    private $createdAt;

    #[ORM\Column(name: 'softdeletedat', type: 'datetime', nullable: true)]
    private $softDeletedAt;

    #[ORM\Column(name: 'updatedat', type: 'datetime')]
    private $updatedAt;

    #[ORM\Column(name: 'releasedat', type: 'datetime', nullable: true)]
    private $releasedAt;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
        $this->require = new ArrayCollection();
        $this->replace = new ArrayCollection();
        $this->conflict = new ArrayCollection();
        $this->provide = new ArrayCollection();
        $this->devRequire = new ArrayCollection();
        $this->suggest = new ArrayCollection();
        $this->authors = new ArrayCollection();
        $this->createdAt = new \DateTime;
        $this->updatedAt = new \DateTime;
    }

    public function toArray(array $versionData = [])
    {
        $tags = [];
        if (isset($versionData[$this->id]['keywords'])) {
            $tags = array_values(array_filter($versionData[$this->id]['keywords']));
        } else if (!isset($versionData[$this->id])) {
            foreach ($this->getTags() as $tag) {
                $tags[] = $tag->getName();
            }
        }

        $authors = [];
        if (isset($versionData[$this->id]['authors'])) {
            $authors = $versionData[$this->id]['authors'];
        } else if (!isset($versionData[$this->id])) {
            foreach ($this->getAuthors() as $author) {
                /** @var $author Author */
                $authors[] = $author->toArray();
            }
        }

        $data = [
            'name' => $this->getName(),
            'description' => (string) $this->getDescription(),
            'keywords' => $tags,
            'homepage' => (string) $this->getHomepage(),
            'version' => $this->getVersion(),
            'version_normalized' => MetadataMinifier::getNormalizedVersionV1($this->normalizedVersion),
            'version_normalized_v2' => $this->getNormalizedVersion(),
            'license' => $this->getLicense(),
            'authors' => $authors,
            'source' => $this->getSource(),
            'dist' => $this->distNormalized ?: $this->getDist(),
            'type' => $this->getType(),
        ];

        if ($this->getReleasedAt()) {
            $data['time'] = $this->getReleasedAt()->format('Y-m-d\TH:i:sP');
        }
        if ($this->getAutoload()) {
            $data['autoload'] = $this->getAutoload();
        }
        if ($this->getExtra()) {
            $data['extra'] = $this->getExtra();
        }
        if ($this->getTargetDir()) {
            $data['target-dir'] = $this->getTargetDir();
        }
        if ($this->getIncludePaths()) {
            $data['include-path'] = $this->getIncludePaths();
        }
        if ($this->getBinaries()) {
            $data['bin'] = $this->getBinaries();
        }

        $supportedLinkTypes = [
            'require'    => 'require',
            'devRequire' => 'require-dev',
            'suggest'    => 'suggest',
            'conflict'   => 'conflict',
            'provide'    => 'provide',
            'replace'    => 'replace',
        ];

        foreach ($supportedLinkTypes as $method => $linkType) {
            if (isset($versionData[$this->id][$method])) {
                foreach ($versionData[$this->id][$method] as $link) {
                    $data[$linkType][$link['name']] = $link['version'];
                }
                continue;
            }
            foreach ($this->{'get'.$method}() as $link) {
                $link = $link->toArray();
                $data[$linkType][key($link)] = current($link);
            }
        }

        if ($this->getPackage()->isAbandoned()) {
            $data['abandoned'] = $this->getPackage()->getReplacementPackage() ?: true;
        }

        return $data;
    }

    public function equals(Version $version)
    {
        return strtolower($version->getName()) === strtolower($this->getName())
            && strtolower($version->getNormalizedVersion()) === strtolower($this->getNormalizedVersion());
    }

    /**
     * Get id
     *
     * @return string $id
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
     * @return string $name
     */
    public function getName()
    {
        return $this->name;
    }

    public function getNames()
    {
        $names = array(
            strtolower($this->name) => true
        );

        foreach ($this->getReplace() as $link) {
            $names[strtolower($link->getPackageName())] = true;
        }

        foreach ($this->getProvide() as $link) {
            $names[strtolower($link->getPackageName())] = true;
        }

        return array_keys($names);
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
     * @return string $description
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set homepage
     *
     * @param string $homepage
     */
    public function setHomepage($homepage)
    {
        $this->homepage = $homepage;
    }

    /**
     * Get homepage
     *
     * @return string $homepage
     */
    public function getHomepage()
    {
        return $this->homepage;
    }

    /**
     * Set version
     *
     * @param string $version
     */
    public function setVersion($version)
    {
        $this->version = $version;
    }

    /**
     * Get version
     *
     * @return string $version
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * @return string
     */
    public function getRequireVersion()
    {
        return preg_replace('{^v(\d)}', '$1', str_replace('.x-dev', '.*@dev', $this->getVersion()));
    }

    /**
     * Set normalizedVersion
     *
     * @param string $normalizedVersion
     */
    public function setNormalizedVersion($normalizedVersion)
    {
        $this->normalizedVersion = $normalizedVersion;
    }

    /**
     * Get normalizedVersion
     *
     * @return string $normalizedVersion
     */
    public function getNormalizedVersion()
    {
        return $this->normalizedVersion;
    }

    /**
     * Set license
     *
     * @param array $license
     */
    public function setLicense(array $license)
    {
        $this->license = json_encode($license);
    }

    /**
     * Get license
     *
     * @return array $license
     */
    public function getLicense()
    {
        return $this->license ? json_decode($this->license, true) : null;
    }

    /**
     * Set source
     *
     * @param array|null $source
     */
    public function setSource($source)
    {
        $this->source = null === $source ? $source : json_encode($source);
    }

    /**
     * Get source
     *
     * @return array|null
     */
    public function getSource()
    {
        return $this->source ? json_decode($this->source, true) : null;
    }

    public function getSourceType(): ?string
    {
        $type = ($this->getSource()['type'] ?? null);

        return is_string($type) ? $type : null;
    }

    public function getSourceReference(): ?string
    {
        $reference = $this->getSource()['reference'] ?? null;

        return is_string($reference) ? $reference : null;
    }

    /**
     * Set dist
     *
     * @param array|null $dist
     */
    public function setDist($dist)
    {
        $this->distNormalized = null;
        $this->dist = null === $dist ? $dist : json_encode($dist);
    }

    /**
     * Get dist
     *
     * @return array|null
     */
    public function getDist()
    {
        return $this->dist ? json_decode($this->dist, true) : null;
    }

    public function getReference(): ?string
    {
        return $this->getDist()['reference'] ?? ($this->getSource()['reference'] ?? null);
    }

    /**
     * @param array $dist
     * @return bool
     */
    public function isEqualsDist(array $dist)
    {
        if ($oldDist = $this->getDist()) {
            sort($oldDist);
            sort($dist);
            return $dist == $oldDist;
        }

        return false;
    }

    /**
     * Set autoload
     *
     * @param array $autoload
     */
    public function setAutoload($autoload)
    {
        $this->autoload = json_encode($autoload);
    }

    /**
     * Get autoload
     *
     * @return array|null
     */
    public function getAutoload()
    {
        return $this->autoload ? json_decode($this->autoload, true) : null;
    }

    /**
     * Set binaries
     *
     * @param array $binaries
     */
    public function setBinaries($binaries)
    {
        $this->binaries = null === $binaries ? $binaries : json_encode($binaries);
    }

    /**
     * Get binaries
     *
     * @return array|null
     */
    public function getBinaries()
    {
        return $this->binaries ? json_decode($this->binaries, true) : null;
    }

    /**
     * Set include paths.
     *
     * @param array $paths
     */
    public function setIncludePaths($paths)
    {
        $this->includePaths = $paths ? json_encode($paths) : null;
    }

    /**
     * Get include paths.
     *
     * @return array|null
     */
    public function getIncludePaths()
    {
        return $this->includePaths ? json_decode($this->includePaths, true) : null;
    }

    /**
     * Set support
     *
     * @param array $support
     */
    public function setSupport($support)
    {
        $this->support = $support ? json_encode($support) : null;
    }

    /**
     * Get support
     *
     * @return array|null
     */
    public function getSupport()
    {
        return $this->support ? json_decode($this->support, true) : null;
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

    /**
     * Set releasedAt
     *
     * @param \DateTime|null $releasedAt
     */
    public function setReleasedAt($releasedAt)
    {
        $this->releasedAt = $releasedAt;
    }

    /**
     * Get releasedAt
     *
     * @return \DateTime
     */
    public function getReleasedAt()
    {
        return $this->releasedAt;
    }

    /**
     * Set package
     *
     * @param Package $package
     */
    public function setPackage(Package $package)
    {
        $this->package = $package;
    }

    /**
     * Get package
     *
     * @return Package
     */
    public function getPackage()
    {
        return $this->package;
    }

    /**
     * Get tags
     *
     * @return Tag[]
     */
    public function getTags()
    {
        return $this->tags;
    }

    /**
     * Set updatedAt
     *
     * @param \DateTime $updatedAt
     */
    public function setUpdatedAt($updatedAt)
    {
        $this->updatedAt = $updatedAt;
    }

    /**
     * Get updatedAt
     *
     * @return \DateTime $updatedAt
     */
    public function getUpdatedAt()
    {
        return $this->updatedAt;
    }

    /**
     * Set softDeletedAt
     *
     * @param \DateTime|null $softDeletedAt
     */
    public function setSoftDeletedAt($softDeletedAt)
    {
        $this->softDeletedAt = $softDeletedAt;
    }

    /**
     * Get softDeletedAt
     *
     * @return \DateTime|null $softDeletedAt
     */
    public function getSoftDeletedAt()
    {
        return $this->softDeletedAt;
    }

    /**
     * Get authors
     *
     * @return Author[]
     */
    public function getAuthors()
    {
        return $this->authors;
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
     * Set targetDir
     *
     * @param string $targetDir
     */
    public function setTargetDir($targetDir)
    {
        $this->targetDir = $targetDir;
    }

    /**
     * Get targetDir
     *
     * @return string
     */
    public function getTargetDir()
    {
        return $this->targetDir;
    }

    /**
     * Set extra
     *
     * @param array $extra
     */
    public function setExtra($extra)
    {
        $this->extra = $extra;
    }

    /**
     * Get extra
     *
     * @return array
     */
    public function getExtra()
    {
        return $this->extra;
    }

    /**
     * Set development
     *
     * @param Boolean $development
     */
    public function setDevelopment($development)
    {
        $this->development = $development;
    }

    /**
     * Get development
     *
     * @return Boolean
     */
    public function getDevelopment()
    {
        return $this->development;
    }

    /**
     * @return Boolean
     */
    public function isDevelopment()
    {
        return $this->getDevelopment();
    }

    /**
     * Add tag
     *
     * @param Tag $tag
     */
    public function addTag(Tag $tag)
    {
        $this->tags[] = $tag;
    }

    /**
     * Add authors
     *
     * @param Author $author
     */
    public function addAuthor(Author $author)
    {
        $this->authors[] = $author;
    }

    /**
     * Add require
     *
     * @param RequireLink $require
     */
    public function addRequireLink(RequireLink $require)
    {
        $this->require[] = $require;
    }

    /**
     * Get require
     *
     * @return RequireLink[]
     */
    public function getRequire()
    {
        return $this->require;
    }

    /**
     * Add replace
     *
     * @param ReplaceLink $replace
     */
    public function addReplaceLink(ReplaceLink $replace)
    {
        $this->replace[] = $replace;
    }

    /**
     * Get replace
     *
     * @return ReplaceLink[]
     */
    public function getReplace()
    {
        return $this->replace;
    }

    /**
     * Add conflict
     *
     * @param ConflictLink $conflict
     */
    public function addConflictLink(ConflictLink $conflict)
    {
        $this->conflict[] = $conflict;
    }

    /**
     * Get conflict
     *
     * @return ConflictLink[]
     */
    public function getConflict()
    {
        return $this->conflict;
    }

    /**
     * Add provide
     *
     * @param ProvideLink $provide
     */
    public function addProvideLink(ProvideLink $provide)
    {
        $this->provide[] = $provide;
    }

    /**
     * Get provide
     *
     * @return ProvideLink[]
     */
    public function getProvide()
    {
        return $this->provide;
    }

    /**
     * Add devRequire
     *
     * @param DevRequireLink $devRequire
     */
    public function addDevRequireLink(DevRequireLink $devRequire)
    {
        $this->devRequire[] = $devRequire;
    }

    /**
     * Get devRequire
     *
     * @return DevRequireLink[]
     */
    public function getDevRequire()
    {
        return $this->devRequire;
    }

    /**
     * Add suggest
     *
     * @param SuggestLink $suggest
     */
    public function addSuggestLink(SuggestLink $suggest)
    {
        $this->suggest[] = $suggest;
    }

    /**
     * Get suggest
     *
     * @return SuggestLink[]
     */
    public function getSuggest()
    {
        return $this->suggest;
    }

    /**
     * @return Boolean
     */
    public function hasVersionAlias()
    {
        return $this->getDevelopment() && $this->getVersionAlias();
    }

    /**
     * @return string
     */
    public function getVersionAlias()
    {
        $extra = $this->getExtra();

        if (isset($extra['branch-alias'][$this->getVersion()])) {
            $parser = new VersionParser;
            $version = $parser->normalizeBranch(str_replace('-dev', '', $extra['branch-alias'][$this->getVersion()]));
            return preg_replace('{(\.9{7})+}', '.x', $version);
        }

        return '';
    }

    /**
     * @return string
     */
    public function getRequireVersionAlias()
    {
        return str_replace('.x-dev', '.*@dev', $this->getVersionAlias());
    }

    public function __toString()
    {
        return $this->name.' '.$this->version.' ('.$this->normalizedVersion.')';
    }
}
