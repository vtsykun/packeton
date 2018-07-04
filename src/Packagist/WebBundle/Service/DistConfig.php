<?php declare(strict_types=1);

namespace Packagist\WebBundle\Service;

use Symfony\Component\Routing\RouterInterface;

class DistConfig
{
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
        $targetDir = \sprintf('%s/%s', $this->config['basedir'], $intermediatePath);

        return $targetDir;
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
     *
     * @return string
     */
    public function generateDistFileName(string $name, string $reference):  string
    {
        $targetDir = $this->generateTargetDir($name);
        return $targetDir . '/' . $reference . '.' . $this->getArchiveFormat();
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
     *
     * @return string
     */
    public function generateRoute(string $name, string $reference): string
    {
        $uri = $this->router->generate(
            'download_dist_package',
            ['package' => $name, 'hash' => $reference . '.' . $this->getArchiveFormat()]
        );

        return $this->config['endpoint'] . $uri;
    }

    /**
     * @return bool
     */
    public function isEnable(): bool
    {
        return !empty($this->config);
    }
}
