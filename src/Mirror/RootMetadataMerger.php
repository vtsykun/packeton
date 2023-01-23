<?php

declare(strict_types=1);

namespace Packeton\Mirror;

use Packeton\Mirror\Model\JsonMetadata;
use Symfony\Component\Routing\RouterInterface;

class RootMetadataMerger
{
    public function __construct(
        protected RouterInterface $router
    ) {
    }

    public function merge(JsonMetadata ...$stamps): JsonMetadata
    {
        if (count($stamps) > 1) {
            throw new \LogicException('Todo, not implements');
        }

        $stamps = $stamps[0];
        $rootFile = $stamps->decodeJson();
        $opt = $stamps->getOption();

        // To avoid call parent host
        unset($rootFile['providers-api']);

        if (!$opt->parentNotify()) {
            unset($rootFile['notify-batch']);
        }
        if ($opt->info()) {
            $rootFile['info'] = $opt->info();
        }

        $newFile = [];
        $url = $this->router->generate('mirror_metadata_v2', ['package' => 'VND/PKG', 'alias' => $opt->getAlias()]);
        $newFile['metadata-url'] = str_replace('VND/PKG', '%package%', $url);

        if ($providerIncludes = $rootFile['provider-includes'] ?? []) {
            foreach ($providerIncludes as $name => $value) {
                unset($providerIncludes[$name]);
                $providerIncludes[ltrim($name, '/')] = $value;
            }

            $rootFile['provider-includes'] = $providerIncludes;
        }

        if ($providerUrl = $rootFile['providers-url'] ?? null) {
            $hasHash = str_contains($providerUrl, '%hash%');
            $url = $this->router->generate('mirror_metadata_v1', ['package' => 'VND/PKG', 'alias' => $opt->getAlias()]);
            $rootFile['providers-url'] = str_replace('VND/PKG', $hasHash ? '%package%$%hash%' : '%package%', $url);
        }

        if ($opt->getAvailablePatterns()) {
            $newFile['available-package-patterns'] = $opt->getAvailablePatterns();
        }
        if ($opt->disableV1Format()) {
            unset($rootFile['packages'], $rootFile['providers']);
        }
        if (empty($rootFile['packages'] ?? null)) {
            unset($rootFile['packages']);
        }

        return $stamps->withContent(array_merge($newFile, $rootFile));
    }
}
