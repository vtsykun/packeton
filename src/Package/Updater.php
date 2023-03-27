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

namespace Packeton\Package;

use cebe\markdown\GithubMarkdown;
use Composer\Package\Archiver\ArchiveManager;
use Composer\Package\AliasPackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\VcsRepository;
use Composer\Repository\Vcs\GitHubDriver;
use Composer\Repository\InvalidRepositoryException;
use Composer\Util\ErrorHandler;
use Composer\Util\Filesystem;
use Composer\Util\RemoteFilesystem;
use Composer\Config;
use Composer\IO\IOInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Packeton\Composer\PackagistFactory;
use Packeton\Composer\Repository\PacketonRepositoryInterface;
use Packeton\Entity\Author;
use Packeton\Entity\Package;
use Packeton\Entity\Tag;
use Packeton\Entity\Version;
use Packeton\Entity\SuggestLink;
use Packeton\Event\UpdaterEvent;
use Packeton\Model\ProviderManager;
use Packeton\Service\DistConfig;
use Packeton\Util\PacketonUtils;
use Seld\Signal\SignalHandler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Updater implements UpdaterInterface
{
    const UPDATE_EQUAL_REFS = 1;
    const DELETE_BEFORE = 2;

    /**
     * Supported link types
     * @var array
     */
    protected $supportedLinkTypes = [
        'require'     => [
            'method' => 'getRequires',
            'entity' => 'RequireLink',
        ],
        'conflict'    => [
            'method' => 'getConflicts',
            'entity' => 'ConflictLink',
        ],
        'provide'     => [
            'method' => 'getProvides',
            'entity' => 'ProvideLink',
        ],
        'replace'     => [
            'method' => 'getReplaces',
            'entity' => 'ReplaceLink',
        ],
        'devRequire' => [
            'method' => 'getDevRequires',
            'entity' => 'DevRequireLink',
        ],
    ];

    public function __construct(
        protected ManagerRegistry $doctrine,
        protected DistConfig $distConfig,
        protected PackagistFactory $packagistFactory,
        protected ProviderManager $providerManager,
        protected EventDispatcherInterface $dispatcher,
    ) {
        ErrorHandler::register();
    }

    /**
     * {@inheritdoc}
     */
    public static function supportRepoTypes(): iterable
    {
        return ['vcs'];
    }

    /**
     * {@inheritdoc}
     */
    public function update(IOInterface $io, Config $config, Package $package, RepositoryInterface $repository, $flags = 0, SignalHandler $signal = null): Package
    {
        $rfs = new RemoteFilesystem($io, $config);

        $stabilityVersionUpdated = 0;
        $deleteDate = new \DateTime();
        $deleteDate->modify('-1day');

        /** @var EntityManagerInterface $em */
        $em = $this->doctrine->getManager();
        $rootIdentifier = $archiveManager = null;

        if ($repository instanceof PacketonRepositoryInterface) {
            $archiveManager = $this->packagistFactory->createArchiveManager($io, $repository);
            $archiveManager->setOverwriteFiles(false);
        }

        if ($repository instanceof VcsRepository) {
            $rootIdentifier = $repository->getDriver()->getRootIdentifier();
        }

        $versions = PacketonUtils::sort($repository->getPackages());
        $versionRepository = $this->doctrine->getRepository(Version::class);

        if ($flags & self::DELETE_BEFORE) {
            foreach ($package->getVersions() as $version) {
                $versionRepository->remove($version);
            }

            $stabilityVersionUpdated = 3;
            $em->flush();
            $em->refresh($package);
        }

        $existingVersions = $versionRepository->getVersionMetadataForUpdate($package);

        $lastProcessed = null;
        $idsToMarkUpdated = $newVersions = $updatedVersions = $deletedVersions = [];
        foreach ($versions as $version) {
            if ($version instanceof AliasPackage) {
                continue;
            }

            if ($lastProcessed && $lastProcessed->getVersion() === $version->getVersion()) {
                $io->write('Skipping version '.$version->getPrettyVersion().' (duplicate of '.$lastProcessed->getPrettyVersion().')', true, IOInterface::VERBOSE);
                continue;
            }
            $lastProcessed = $version;

            $result = $this->updateInformation(
                $archiveManager,
                $package,
                $existingVersions,
                $version,
                $flags,
                $rootIdentifier
            );

            $lastUpdated = $result['updated'];

            if ($lastUpdated) {
                $em->flush();
                $em->clear();
                $package = $em->find(Package::class, $package->getId());
                $version = $result['object'] ?? null;
                if (!isset($result['id']) && $version instanceof Version) {
                    $result['id'] = $version->getId();
                }

                $stabilityVersionUpdated |= $lastProcessed->isDev() ? ProviderManager::DEV_UPDATED : ProviderManager::STAB_UPDATED;
            } else {
                $idsToMarkUpdated[] = $result['id'];
            }

            if ($lastUpdated) {
                if (isset($existingVersions[$result['version']])) {
                    $updatedVersions[] = $result['id'];
                } else {
                    $newVersions[] = $result['id'];
                }
            }

            // mark the version processed so we can prune leftover ones
            unset($existingVersions[$result['version']]);
        }

        // mark versions that did not update as updated to avoid them being pruned
        $em->getConnection()->executeStatement(
            'UPDATE package_version SET updatedAt = :now, softDeletedAt = NULL WHERE id IN (:ids)',
            ['now' => date('Y-m-d H:i:s'), 'ids' => $idsToMarkUpdated],
            ['ids' => ArrayParameterType::INTEGER]
        );

        // remove outdated versions
        foreach ($existingVersions as $version) {
            if (!is_null($version['soft_deleted_at']) && new \DateTime($version['soft_deleted_at']) < $deleteDate) {
                $deletedVersions[] = $version['id'];
            } else {
                // set it to be soft-deleted so next update that occurs after deleteDate (1day) if the
                // version is still missing it will be really removed
                $stabilityVersionUpdated = 3;
                $em->getConnection()->executeStatement(
                    'UPDATE package_version SET softDeletedAt = :now WHERE id = :id',
                    ['now' => date('Y-m-d H:i:s'), 'id' => $version['id']]
                );
            }
        }

        $isNewPackage = false;
        if (null === $package->getUpdatedAt()) {
            $isNewPackage = true;
        } elseif ($deletedVersions || $updatedVersions || $newVersions) {
            $this->dispatcher->dispatch(
                new UpdaterEvent($package, $flags, $newVersions, $updatedVersions, $deletedVersions),
                UpdaterEvent::VERSIONS_UPDATE
            );
        }

        foreach ($deletedVersions as $versionId) {
            $stabilityVersionUpdated = 3;
            $version = $versionRepository->find($versionId);
            $versionRepository->remove($version);
        }

        if ($repository instanceof VcsRepository) {
            $isUpdated = false;
            if (preg_match('{^(?:git://|git@|https?://)github.com[:/]([^/]+)/(.+?)(?:\.git|/)?$}i', $package->getRepository(), $match)) {
                $isUpdated = $this->updateGitHubInfo($rfs, $package, $match[1], $match[2], $repository);
            }
            if (false === $isUpdated) {
                $this->updateReadme($io, $package, $repository);
            }
        }

        if ($stabilityVersionUpdated !== 0) {
            $this->providerManager->setLastModify($package->getName(), $stabilityVersionUpdated);
        }

        $package->setUpdatedAt(new \DateTime('now', new \DateTimeZone('UTC')));
        $package->setCrawledAt(new \DateTime('now', new \DateTimeZone('UTC')));
        $em->flush();

        if (true === $isNewPackage) {
            $this->dispatcher->dispatch(new UpdaterEvent($package, $flags), UpdaterEvent::PACKAGE_PERSIST);
        }

        if (!$this->providerManager->packageExists($package->getName())) {
            $this->providerManager->getPackageNames(true);
        }

        if ($repository instanceof VcsRepository && $repository->hadInvalidBranches()) {
            throw new InvalidRepositoryException('Some branches contained invalid data and were discarded, it is advised to review the log and fix any issues present in branches');
        }

        return $package;
    }

    /**
     * @param ArchiveManager|null $archiveManager
     * @param Package $package
     * @param array $existingVersions
     * @param PackageInterface|CompletePackageInterface $data
     * @param $flags
     * @param $rootIdentifier
     *
     * @return array with keys:
     *                    - updated (whether the version was updated or needs to be marked as updated)
     *                    - id (version id, can be null for newly created versions)
     *                    - version (normalized version from the composer package)
     *                    - object (Version instance if it was updated)
     */
    private function updateInformation(
        ?ArchiveManager $archiveManager,
        Package $package,
        array $existingVersions,
        PackageInterface $data,
        $flags,
        $rootIdentifier
    ) {
        /** @var EntityManagerInterface $em */
        $em = $this->doctrine->getManager();
        $versionRepo = $this->doctrine->getRepository(Version::class);
        $version = new Version();

        $normVersion = $data->getVersion();

        $existingVersion = $existingVersions[strtolower($normVersion)] ?? null;
        if ($existingVersion) {
            $source = $existingVersion['source'];
            // update if the right flag is set, or the source reference has changed (re-tag or new commit on branch)
            if ($source['reference'] !== $data->getSourceReference() || ($flags & self::UPDATE_EQUAL_REFS)) {
                $version = $versionRepo->find($existingVersion['id']);
            } else {
                $updated = false;
                $version = $versionRepo->find($existingVersion['id']);
                if ($dist = $this->updateArchive($archiveManager, $data)) {
                    $updated = $this->updateDist($dist, $version);
                }

                return [
                    'updated' => $updated,
                    'id' => $existingVersion['id'],
                    'version' => strtolower($normVersion),
                    'object' => $updated ? $version : null
                ];
            }
        }

        $version->setName($package->getName());
        $version->setVersion($data->getPrettyVersion());
        $version->setNormalizedVersion($normVersion);
        $version->setDevelopment($data->isDev());

        $em->persist($version);

        $descr = $this->sanitize($data->getDescription());
        $version->setDescription($descr);

        // update the package description only for the default branch
        if ($rootIdentifier === null || preg_replace('{dev-|-dev}', '', $version->getVersion()) === $rootIdentifier) {
            $package->setDescription($descr);
        }

        $version->setHomepage($data->getHomepage());
        $version->setLicense($data->getLicense() ?: []);

        $version->setPackage($package);
        $version->setUpdatedAt(new \DateTime);
        $version->setSoftDeletedAt(null);
        $version->setReleasedAt($data->getReleaseDate());

        if ($data->getSourceType()) {
            $source['type'] = $data->getSourceType();
            $source['url'] = $data->getSourceUrl();
            $source['reference'] = $data->getSourceReference();
            $version->setSource($source);
        } else {
            $version->setSource(null);
        }

        if ($dist = $this->updateArchive($archiveManager, $data)) {
            $this->updateDist($dist, $version);
        } elseif ($data->getDistType()) {
            $dist['type'] = $data->getDistType();
            $dist['url'] = $data->getDistUrl();
            $dist['reference'] = $data->getDistReference();
            $dist['shasum'] = $data->getDistSha1Checksum();
            $version->setDist($dist);
        } else {
            $version->setDist(null);
        }

        if ($data->getType()) {
            $type = $this->sanitize($data->getType());
            $version->setType($type);
            if ($type !== $package->getType()) {
                $package->setType($type);
            }
        }

        $version->setTargetDir($data->getTargetDir());
        $version->setAutoload($data->getAutoload());
        $version->setExtra($data->getExtra());
        $version->setBinaries($data->getBinaries());
        $version->setIncludePaths($data->getIncludePaths());
        $version->setSupport($data->getSupport());

        if ($data->getKeywords()) {
            $keywords = [];
            foreach ($data->getKeywords() as $keyword) {
                $keywords[mb_strtolower($keyword, 'UTF-8')] = $keyword;
            }

            $existingTags = [];
            foreach ($version->getTags() as $tag) {
                $existingTags[mb_strtolower($tag->getName(), 'UTF-8')] = $tag;
            }

            foreach ($keywords as $tagKey => $keyword) {
                if (isset($existingTags[$tagKey])) {
                    unset($existingTags[$tagKey]);
                    continue;
                }

                $tag = Tag::getByName($em, $keyword, true);
                if (!$version->getTags()->contains($tag)) {
                    $version->addTag($tag);
                }
            }

            foreach ($existingTags as $tag) {
                $version->getTags()->removeElement($tag);
            }
        } elseif (count($version->getTags())) {
            $version->getTags()->clear();
        }

        $authorRepository = $this->doctrine->getRepository(Author::class);

        $version->getAuthors()->clear();
        if ($data->getAuthors()) {
            foreach ($data->getAuthors() as $authorData) {
                $author = null;

                foreach (['email', 'name', 'homepage', 'role'] as $field) {
                    if (isset($authorData[$field])) {
                        $authorData[$field] = trim($authorData[$field]);
                        if ('' === $authorData[$field]) {
                            $authorData[$field] = null;
                        }
                    } else {
                        $authorData[$field] = null;
                    }
                }

                // skip authors with no information
                if (!isset($authorData['email']) && !isset($authorData['name'])) {
                    continue;
                }

                $author = $authorRepository->findOneBy([
                    'email' => $authorData['email'],
                    'name' => $authorData['name'],
                    'homepage' => $authorData['homepage'],
                    'role' => $authorData['role'],
                ]);

                if (!$author) {
                    $author = new Author();
                    $em->persist($author);
                }

                foreach (['email', 'name', 'homepage', 'role'] as $field) {
                    if (isset($authorData[$field])) {
                        $author->{'set'.$field}($authorData[$field]);
                    }
                }

                // only update the author timestamp once a month at most as the value is kinda unused
                if ($author->getUpdatedAt() === null || $author->getUpdatedAt()->getTimestamp() < time() - 86400 * 30) {
                    $author->setUpdatedAt(new \DateTime);
                }
                if (!$version->getAuthors()->contains($author)) {
                    $version->addAuthor($author);
                }
            }
        }

        // handle links
        foreach ($this->supportedLinkTypes as $linkType => $opts) {
            $links = [];
            foreach ($data->{$opts['method']}() as $link) {
                $constraint = $link->getPrettyConstraint();
                if (str_contains($constraint, ',') && str_contains($constraint, '@')) {
                    $constraint = preg_replace_callback('{([><]=?\s*[^@]+?)@([a-z]+)}i', function ($matches) {
                        if ($matches[2] === 'stable') {
                            return $matches[1];
                        }

                        return $matches[1].'-'.$matches[2];
                    }, $constraint);
                }

                $links[$link->getTarget()] = $constraint;
            }

            foreach ($version->{'get'.$linkType}() as $link) {
                // clear links that have changed/disappeared (for updates)
                if (!isset($links[$link->getPackageName()]) || $links[$link->getPackageName()] !== $link->getPackageVersion()) {
                    $version->{'get'.$linkType}()->removeElement($link);
                    $em->remove($link);
                } else {
                    // clear those that are already set
                    unset($links[$link->getPackageName()]);
                }
            }

            foreach ($links as $linkPackageName => $linkPackageVersion) {
                $class = 'Packeton\Entity\\'.$opts['entity'];
                $link = new $class;
                $link->setPackageName($linkPackageName);
                $link->setPackageVersion($linkPackageVersion);
                $version->{'add'.$linkType.'Link'}($link);
                $link->setVersion($version);
                $em->persist($link);
            }
        }

        // handle suggests
        if ($suggests = $data->getSuggests()) {
            foreach ($version->getSuggest() as $link) {
                // clear links that have changed/disappeared (for updates)
                if (!isset($suggests[$link->getPackageName()]) || $suggests[$link->getPackageName()] !== $link->getPackageVersion()) {
                    $version->getSuggest()->removeElement($link);
                    $em->remove($link);
                } else {
                    // clear those that are already set
                    unset($suggests[$link->getPackageName()]);
                }
            }

            foreach ($suggests as $linkPackageName => $linkPackageVersion) {
                $link = new SuggestLink();
                $link->setPackageName($linkPackageName);
                $link->setPackageVersion($linkPackageVersion);
                $version->addSuggestLink($link);
                $link->setVersion($version);
                $em->persist($link);
            }
        } elseif (count($version->getSuggest())) {
            // clear existing suggests if present
            foreach ($version->getSuggest() as $link) {
                $em->remove($link);
            }
            $version->getSuggest()->clear();
        }

        return [
            'updated' => true,
            'id' => $version->getId(),
            'version' => strtolower($normVersion),
            'object' => $version
        ];
    }

    /**
     * @param ArchiveManager|null $archiveManager
     * @param PackageInterface|CompletePackageInterface $data
     *
     * @return array|null
     */
    private function updateArchive(?ArchiveManager $archiveManager, PackageInterface $data): ?array
    {
        if ($this->distConfig->isEnable() === false) {
            if ($data->getDistUrl()) {
                return [
                    'url' => $data->getDistUrl(),
                    'type' => $data->getDistType(),
                    'reference' => $data->getDistReference(),
                ];
            }

            return null;
        }

        if (false === $this->distConfig->isLazy() && $archiveManager !== null) {
            $fileName = $this->distConfig->getFileName(
                $data->getSourceReference(),
                $data->getVersion()
            );

            $path = $archiveManager->archive(
                $data,
                $this->distConfig->getArchiveFormat(),
                $this->distConfig->generateTargetDir($data->getName()),
                $fileName
            );
            $dist['shasum'] = $this->distConfig->isIncludeArchiveChecksum() ? \hash_file('sha1', $path) : null;
        }

        $dist['type'] = $this->distConfig->getArchiveFormat();
        $dist['url'] = $this->distConfig->generateRoute($data->getName(), $data->getSourceReference());
        $dist['reference'] = $data->getSourceReference();

        return $dist;
    }

    private function updateDist(array $dist, Version $version)
    {
        if ($version->isEqualsDist($dist)) {
            return false;
        }

        $filesystem = new Filesystem();
        $oldDist = $version->getDist();
        if (isset($oldDist['reference']) && $dist['reference'] !== $oldDist['reference']) {
            $targetDir = $this->distConfig->generateDistFileName(
                $version->getName(),
                $oldDist['reference'],
                $version->getVersion()
            );
            $filesystem->remove($targetDir);
        }

        $version->setDist($dist);

        return true;
    }

    /**
     * Update the readme for $package from $repository.
     *
     * @param IOInterface $io
     * @param Package $package
     * @param VcsRepository $repository
     */
    private function updateReadme(IOInterface $io, Package $package, VcsRepository $repository)
    {
        try {
            $driver = $repository->getDriver();
            $composerInfo = $driver->getComposerInformation($driver->getRootIdentifier());
            $readmeFile = $composerInfo['readme'] ?? 'README.md';

            $ext = substr($readmeFile, strrpos($readmeFile, '.'));
            if ($ext === $readmeFile) {
                $ext = '.txt';
            }

            switch ($ext) {
                case '.txt':
                    $source = $driver->getFileContent($readmeFile, $driver->getRootIdentifier());
                    if (!empty($source)) {
                        $package->setReadme('<pre>' . htmlspecialchars($source) . '</pre>');
                    }
                    break;

                case '.md':
                    $source = null;
                    $tries = array_unique([$readmeFile, strtolower($readmeFile), 'README.md', 'Readme.md']);
                    try {
                        foreach ($tries as $readmeFile) {
                            if ($source = $driver->getFileContent($readmeFile, $driver->getRootIdentifier())) {
                                break;
                            }
                        }
                    } catch (\Exception $e) {
                    }

                    if (empty($source)) {
                        return;
                    }

                    $parser = new GithubMarkdown();
                    $readme = $parser->parse($source);

                    if (!empty($readme)) {
                        if (preg_match('{^(?:git://|git@|https?://)(gitlab.com|github.com|bitbucket.org)[:/]([^/]+)/(.+?)(?:\.git|/)?$}i', $package->getRepository(), $match)) {
                            $package->setReadme($this->prepareReadme($readme, $match[1], $match[2], $match[3]));
                        } else {
                            $package->setReadme($this->prepareReadme($readme));
                        }
                    }
                    break;
            }
        } catch (\Exception $e) {
            // we ignore all errors for this minor function
            $io->write(
                'Can not update readme. Error: ' . $e->getMessage(),
                true,
                IOInterface::VERBOSE
            );
        }
    }

    private function updateGitHubInfo(RemoteFilesystem $rfs, Package $package, $owner, $repo, VcsRepository $repository): bool
    {
        $baseApiUrl = 'https://api.github.com/repos/'.$owner.'/'.$repo;
        if ($package->getSubDirectory()) {
            $baseApiUrl .= '/' . trim($package->getSubDirectory(), '/');
        }
        if (null !== $package->getParentPackage()) {
            return false; // Skip to avoid github rate limit.
        }

        try {
            $opts = ['http' => ['header' => ['Accept: application/vnd.github.v3.html']]];
            $readme = $rfs->getContents('github.com', $baseApiUrl.'/readme', false, $opts);
        } catch (\Exception $e) {
            if (!$e instanceof \Composer\Downloader\TransportException || $e->getCode() !== 404) {
                return false;
            }
            // 404s just mean no readme present so we proceed with the rest
        }

        if (!empty($readme)) {
            $package->setReadme($this->prepareReadme($readme, 'github.com', $owner, $repo));
        }

        $driver = $repository->getDriver();
        if (!$driver instanceof GitHubDriver) {
            return true;
        }

        $repoData = $driver->getRepoData();
        if (!empty($repoData['language'])) {
            $package->setLanguage($repoData['language']);
        }
        if (isset($repoData['stargazers_count'])) {
            $package->setGitHubStars($repoData['stargazers_count']);
        }
        if (isset($repoData['subscribers_count'])) {
            $package->setGitHubWatches($repoData['subscribers_count']);
        }
        if (isset($repoData['network_count'])) {
            $package->setGitHubForks($repoData['network_count']);
        }
        if (isset($repoData['open_issues_count'])) {
            $package->setGitHubOpenIssues($repoData['open_issues_count']);
        }

        return true;
    }

    private function prepareReadme(string $readme, ?string $host = null, ?string $owner = null, ?string $repo = null): string
    {
        $elements = [
            'p',
            'br',
            'small',
            'strong', 'b',
            'em', 'i',
            'strike',
            'sub', 'sup',
            'ins', 'del',
            'ol', 'ul', 'li',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'dl', 'dd', 'dt',
            'pre', 'code', 'samp', 'kbd',
            'q', 'blockquote', 'abbr', 'cite',
            'table', 'thead', 'tbody', 'th', 'tr', 'td',
            'a', 'span',
            'img',
            'details', 'summary',
        ];

        $attributes = [
            'img.src', 'img.title', 'img.alt', 'img.width', 'img.height', 'img.style',
            'a.href', 'a.target', 'a.rel', 'a.id',
            'td.colspan', 'td.rowspan', 'th.colspan', 'th.rowspan',
            'th.align', 'td.align', 'p.align',
            'h1.align', 'h2.align', 'h3.align', 'h4.align', 'h5.align', 'h6.align',
            '*.class', 'details.open',
        ];

        // detect base path for github readme if file is located in a subfolder like docs/README.md
        $basePath = '';
        if ($host === 'github.com' && preg_match('{^<div id="readme" [^>]+?data-path="([^"]+)"}', $readme, $match) && str_contains((string)$match[1], '/')) {
            $basePath = dirname($match[1]);
        }
        if ($basePath) {
            $basePath .= '/';
        }

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.AllowedElements', implode(',', $elements));
        $config->set('HTML.AllowedAttributes', implode(',', $attributes));
        $config->set('Attr.EnableID', true);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);

        // add custom HTML tag definitions
        $def = $config->getHTMLDefinition(true);
        $def->addElement('details', 'Block', 'Flow', 'Common', [
            'open' => 'Bool#open',
        ]);
        $def->addElement('summary', 'Inline', 'Inline', 'Common');

        $purifier = new \HTMLPurifier($config);
        $readme = $purifier->purify($readme);

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $readme);

        // Links can not be trusted, mark them nofollow and convert relative to absolute links
        $links = $dom->getElementsByTagName('a');
        foreach ($links as $link) {
            $link->setAttribute('rel', 'nofollow noindex noopener external ugc');
            if (str_starts_with($link->getAttribute('href'), '#')) {
                $link->setAttribute('href', '#user-content-'.substr($link->getAttribute('href'), 1));
            } elseif (str_starts_with($link->getAttribute('href'), 'mailto:')) {
                // do nothing
            } elseif ($host === 'github.com' && !str_contains($link->getAttribute('href'), '//')) {
                $link->setAttribute(
                    'href',
                    'https://github.com/'.$owner.'/'.$repo.'/blob/HEAD/'.$basePath.$link->getAttribute('href')
                );
            } elseif ($host === 'gitlab.com' && !str_contains($link->getAttribute('href'), '//')) {
                $link->setAttribute(
                    'href',
                    'https://gitlab.com/'.$owner.'/'.$repo.'/-/blob/HEAD/'.$basePath.$link->getAttribute('href')
                );
            }
        }

        // embed images of selected hosts by converting relative links to accessible URLs
        if (in_array($host, ['github.com', 'gitlab.com', 'bitbucket.org'], true)) {
            $images = $dom->getElementsByTagName('img');
            foreach ($images as $img) {
                if (!str_contains($img->getAttribute('src'), '//')) {
                    $imgSrc = match ($host) {
                        'github.com' => 'https://raw.github.com/'.$owner.'/'.$repo.'/HEAD/'.$basePath.$img->getAttribute('src'),
                        'gitlab.com' => 'https://gitlab.com/'.$owner.'/'.$repo.'/-/raw/HEAD/'.$basePath.$img->getAttribute('src'),
                        'bitbucket.org' => 'https://bitbucket.org/'.$owner.'/'.$repo.'/raw/HEAD/'.$basePath.$img->getAttribute('src'),
                    };
                    $img->setAttribute('src', $imgSrc);
                }
            }
        }

        // remove first page element if it's a <h1> or <h2>, because it's usually
        // the project name or the `README` string which we don't need
        $first = $dom->getElementsByTagName('body')->item(0);
        if ($first) {
            $first = $first->childNodes->item(0);
        }

        if ($first && ('h1' === $first->nodeName || 'h2' === $first->nodeName)) {
            $first->parentNode?->removeChild($first);
        }

        $readme = $dom->saveHTML() ?: '';
        $readme = substr($readme, strpos($readme, '<body>') + 6);
        $readme = substr($readme, 0, strrpos($readme, '</body>') ?: PHP_INT_MAX);

        libxml_use_internal_errors(false);
        libxml_clear_errors();

        return str_replace("\r\n", "\n", $readme);
    }

    private function sanitize(string|null $str): string|null
    {
        if (null === $str) {
            return null;
        }

        // remove escape chars
        $str = preg_replace("{\x1B(?:\[.)?}u", '', $str);

        return preg_replace("{[\x01-\x1A]}u", '', $str);
    }
}
