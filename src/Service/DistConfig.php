<?php

declare(strict_types=1);

namespace Packeton\Service;

use Symfony\Component\Routing\RouterInterface;

class DistConfig
{
    public const HOSTNAME_PLACEHOLDER = '__host_unset__';

    private $config;
    private $router;

    /**
     * @param RouterInterface $router
     * @param array $config
     */
    public function __construct(RouterInterface $router, array $config)
    {
        $this->config = $config;
        $this->router = $router;
    }

    public function generateTargetDir(string $name)
    {
        $intermediatePath = \preg_replace('#[^a-z0-9-_/]#i', '-', $name);

        return \sprintf('%s/%s', $this->config['basedir'], $intermediatePath);
    }

    public function buildName(string $packageName, string $reference, string $version): string
    {
        $intermediatePath = \preg_replace('#[^a-z0-9-_/]#i', '-', $packageName);
        $filename = str_replace('/', '-', $version . '-' . $reference) . '.' . $this->getArchiveFormat();

        return $intermediatePath . '/' . $filename;
    }

    public function resolvePath(?string $keyName = null): string
    {
        if ($dir = $this->getDistDir()) {
            return $dir . '/' . $keyName;
        }

        throw new \InvalidArgumentException('archive_options[basedir] option can not be empty');
    }

    /**
     * @return string|null
     */
    public function getDistDir(): ?string
    {
        return $this->config['basedir'] ?? null;
    }

    /**
     * @return string
     */
    public function getArchiveFormat(): string
    {
        return $this->config['format'] ?? 'zip';
    }

    /**
     * @param string $name
     * @param string $reference
     * @param string $version
     *
     * @return string
     */
    public function generateDistFileName(string $name, string $reference, string $version):  string
    {
        $targetDir = $this->generateTargetDir($name);
        $fileName = $this->getFileName($reference, $version);
        return $targetDir . '/' . $fileName . '.' . $this->getArchiveFormat();
    }

    /**
     * @param string $reference
     * @param string $version
     * @return string
     */
    public function getFileName(string $reference, string $version): string
    {
        $fileName = $version . '-' . $reference;
        return str_replace('/', '-', $fileName);
    }

    /**
     * @param string $fileName
     * @return string
     */
    public function guessesVersion(string $fileName)
    {
        $fileName = explode('-', $fileName);
        $pathCount = count($fileName);
        if ($pathCount > 1) {
            unset($fileName[$pathCount - 1]);
        }

        $fileName = implode('-', $fileName);
        return preg_replace('/(ticket|feature|fix)-/i', '$1/', $fileName);
    }

    /**
     * @return bool
     */
    public function isIncludeArchiveChecksum(): bool
    {
        return $this->config['include_archive_checksum'] ?? false;
    }

    /**
     * Generate link to download from dist
     *
     * @param string $name
     * @param string $reference
     * @param string $format
     *
     * @return string
     */
    public function generateRoute(string $name, string $reference, string $format = null): string
    {
        $hostName = !isset($this->config['endpoint']) ? self::HOSTNAME_PLACEHOLDER : rtrim($this->config['endpoint'], '/');

        $format ??= '.' . $this->getArchiveFormat();
        if ($format && !str_starts_with($format, '.')) {
            $format = '.' . $format;
        }

        $uri = $this->router->generate(
            'download_dist_package',
            ['package' => $name, 'hash' => $reference . $format]
        );

        return $hostName . $uri;
    }

    /**
     * @return bool
     */
    public function isEnable(): bool
    {
        return !empty($this->config);
    }

    /**
     * @return bool
     */
    public function isLazy(): bool
    {
        return $this->config['lazy'] ?? true;
    }
}
