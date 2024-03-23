<?php

declare(strict_types=1);

namespace Packeton\Composer\Repository\Vcs;

use Composer\Json\JsonFile;
use Composer\Repository\Vcs\GitDriver;
use Composer\Util\ProcessExecutor;

class TreeGitDriver extends GitDriver
{
    protected $subDirectory;

    public function initialize(): void
    {
        $this->subDirectory = $this->repoConfig['subDirectory'] ?? null;

        parent::initialize();
    }

    /**
     * {@inheritdoc}
     */
    public function getComposerInformation(string $identifier): ?array
    {
        $cacheKey = sha1($identifier . $this->subDirectory);
        if (!isset($this->infoCache[$cacheKey])) {
            if ($this->shouldCache($identifier) && $res = $this->cache->read($cacheKey)) {
                return $this->infoCache[$cacheKey] = JsonFile::parseJson($res);
            }

            $composer = $this->getBaseComposerInformation($identifier);

            if ($this->shouldCache($identifier)) {
                $this->cache->write($cacheKey, JsonFile::encode($composer, 0));
            }

            $this->infoCache[$cacheKey] = $composer;
        }

        return $this->infoCache[$cacheKey];
    }

    /**
     * {@inheritdoc}
     */
    public function getFileContent(string $file, string $identifier): ?string
    {
        if ($this->subDirectory) {
            $file = trim($this->subDirectory, '/') . '/' . $file;
        }

        return parent::getFileContent($file, $identifier);
    }

    public function getRepoTree(?string $identifier = null): array
    {
        $identifier ??= $this->getRootIdentifier();
        if (str_starts_with($identifier, '-')) {
            throw new \RuntimeException('Invalid git identifier detected. Identifier must not start with a -, given: ' . $identifier);
        }

        $resource = ProcessExecutor::escape($identifier);
        $this->process->execute(sprintf('git ls-tree -r --name-only %s', $resource), $content, $this->repoDir);

        return $this->process->splitLines($content);
    }

    public function getDiff(string $sha1, string $sha2): array
    {
        if (str_starts_with($sha1, '-') || str_starts_with($sha2, '-')) {
            throw new \RuntimeException('Invalid git identifier detected. Identifier must not start with a -, given: ' . $sha2 . ',' . $sha1);
        }

        $sha1 = ProcessExecutor::escape($sha1);
        $sha2 = ProcessExecutor::escape($sha2);
        $this->process->execute(sprintf('git diff --name-only %s %s', $sha1, $sha2), $content, $this->repoDir);

        $list = $this->process->splitLines($content);
        return array_map(fn($name) => trim($name, '/'), $list);
    }

    public function withSubDirectory(?string $subDirectory): static
    {
        $driver = clone $this;
        $driver->subDirectory = $subDirectory;

        return $driver;
    }

    public function makeArchive(string $identifier, string $targetDir, string $format = 'zip'): ?string
    {
        if (str_starts_with($identifier, '-')) {
            throw new \RuntimeException('Invalid git identifier detected. Identifier must not start with a -, given: ' . $identifier);
        }

        $format = ProcessExecutor::escape($format);
        $subDirectory = $this->subDirectory ? ':' . trim($this->subDirectory, '/') : '';

        $identifier = ProcessExecutor::escape($identifier . $subDirectory);
        $this->process->execute(sprintf('git archive %s --format=%s -o %s', $identifier, $format, ProcessExecutor::escape($targetDir)), $content, $this->repoDir);

        if (file_exists($targetDir) && filesize($targetDir) > 0) {
            return $targetDir;
        }

        return null;
    }
}
