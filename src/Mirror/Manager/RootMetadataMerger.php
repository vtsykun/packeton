<?php

declare(strict_types=1);

namespace Packeton\Mirror\Manager;

use Packeton\Composer\Util\ComposerApi;
use Packeton\Mirror\Model\JsonMetadata;
use Symfony\Component\Routing\RouterInterface;

class RootMetadataMerger
{
    public function __construct(
        protected RouterInterface $router
    ) {
    }

    public function merge(JsonMetadata $stamps, int $composerApi = null): JsonMetadata
    {
        $rootFile = $stamps->decodeJson();
        $config = $stamps->getOptions();

        // To avoid call parent host
        unset($rootFile['providers-api'], $rootFile['search'], $rootFile['list']);

        if (!$config->parentNotify()) {
            unset($rootFile['notify-batch']);
        }
        if ($config->info()) {
            $rootFile['info'] = $config->info();
        }

        $newFile = [];
        $url = $this->router->generate('mirror_metadata_v2', ['package' => 'VND/PKG', 'alias' => $config->getAlias()]);
        $newFile['metadata-url'] = \str_replace('VND/PKG', '%package%', $url);

        if ($providerIncludes = $rootFile['provider-includes'] ?? []) {
            foreach ($providerIncludes as $name => $value) {
                unset($providerIncludes[$name]);
                $providerIncludes[\ltrim($name, '/')] = $value;
            }

            $rootFile['provider-includes'] = $providerIncludes;
        }

        if ($providerUrl = $rootFile['providers-url'] ?? null) {
            $hasHash = \str_contains($providerUrl, '%hash%');
            $url = $this->router->generate('mirror_metadata_v1', ['package' => 'VND/PKG', 'alias' => $config->getAlias()]);
            $rootFile['providers-url'] = \str_replace('VND/PKG', $hasHash ? '%package%$%hash%' : '%package%', $url);
        }

        if ($config->getAvailablePackages()) {
            $newFile['available-packages'] = $config->getAvailablePackages();
        }

        if ($config->getAvailablePatterns() && !isset($newFile['available-packages'])) {
            $newFile['available-package-patterns'] = $config->getAvailablePatterns();
        }
        if ($config->disableV1Format()) {
            unset($rootFile['packages'], $rootFile['providers'], $rootFile['provider-includes'], $rootFile['includes']);
        }
        if (empty($rootFile['packages'] ?? null)) {
            unset($rootFile['packages']);
        }

        if ($config->isLazy() || true === ($rootFile['providers-lazy-url'] ?? false)) {
            unset($rootFile['provider-includes'], $rootFile['providers-url']);
            $url = $this->router->generate('mirror_metadata_v1', ['package' => 'VND/PKG', 'alias' => $config->getAlias()]);
            $rootFile['providers-lazy-url'] = \str_replace('VND/PKG','%package%', $url);
        }

        if ($config->disableV2Format()) {
            $composerApi = 1;
            unset($newFile['metadata-url'], $rootFile['metadata-url']);
        }

        // generate lazy load includes if enabled composer strict approve mode.
        if ($config->disableV1Format() === false && ComposerApi::API_V1 === $composerApi && ($includes = $config->getIncludes())) {
            unset($rootFile['provider-includes'], $rootFile['providers-url'], $rootFile['providers-lazy-url']);
            $rootFile['includes'] = $includes;
        }

        if ($config->isDistMirror()) {
            $zipball = $this->router->generate(
                'mirror_zipball',
                ['package' => 'VND/PKG', 'alias' => $config->getAlias(), 'version' => '__VER', 'ref' => '__REF', 'type' => '__TP']
            );

            $rootFile['mirrors'] = [
                ['dist-url' => \str_replace(['VND/PKG', '__VER', '__REF', '__TP'], ['%package%', '%version%', 'ref%reference%', '%type%'], $zipball), 'preferred' => true]
            ];
        }

        $result = \array_merge($rootFile, $newFile);
        $this->normalizePackagesNode($result);

        return $stamps->withContent($result, \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
    }

    protected function normalizePackagesNode(array &$result): void
    {
        if (!is_array($packages = $result['packages'] ?? null)) {
            return;
        }

        $i = 1;
        foreach ($packages as $packageName => $list) {
            if (!is_array($list)) {
                break;
            }
            foreach ($list as $ver => $pkg) {
                if (isset($pkg['uid'])) {
                    break 2;
                }
                $list[$ver]['uid'] = $i++;
            }
            $packages[$packageName] = $list;
        }
        $result['packages'] = $packages;
    }
}
