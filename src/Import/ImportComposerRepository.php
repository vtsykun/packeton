<?php

declare(strict_types=1);

namespace Packeton\Import;

use Composer\Util\HttpDownloader;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\Service\FetchPackageMetadataService;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class ImportComposerRepository
{
    public const LIMIT_SIZE = 5000;

    public function __construct(
        protected HttpDownloader $http,
        protected ProxyOptions $option,
        protected FetchPackageMetadataService $metadataService,
        protected ?int $limitSize = null,
        protected ?string $filter = null,
    ) {
        if ($option->getRoot() === null) {
            $this->option = $this->initComposer();
        }

        $this->limitSize = (int) min(self::LIMIT_SIZE, $limitSize ?: self::LIMIT_SIZE);
    }

    public function getOptions(): ProxyOptions
    {
        return $this->option;
    }

    public function setOptions(ProxyOptions $option): void
    {
        $this->option = $option;
    }

    public function getPackages(): array
    {
        $packages = $this->loadIncludes($this->option->getRoot());

        $allProviders = [];
        $packageNames = $this->option->getAvailablePackages();
        foreach ($packageNames as $i => $packageName) {
            if (null !== $this->filter && !preg_match($this->filter, $packageName)) {
                unset($packageNames[$i]);
            }
        }

        foreach ($this->lookupAllProviders() as $provider) {
            if (!$provider) {
                continue;
            }

            $allProviders[] = $provider;
            $packageNames = array_merge($packageNames, array_keys($provider));
        }

        $packageNames = array_values(array_unique($packageNames));
        $packageNames = array_slice($packageNames, 0, $this->limitSize);

        if ($packageNames) {
            $result = $this->metadataService->fetchPackageMetadata($packageNames, $this->option, false, $allProviders);
            $packages += $this->loadIncludes(['packages' => $result]);
        }

        $result = [];
        foreach ($packages as $package => $meta) {
            if (isset($meta['source']['url'])) {
                $result[$meta['source']['url']] = $package;
            }
        }

        return $result;
    }

    // See loadIncludes in the ComposerRepository
    protected function loadIncludes(array $data): array
    {
        $packages = [];
        // legacy repo handling
        if (!isset($data['packages']) && !isset($data['includes'])) {
            foreach ($data as $pkg) {
                if (isset($pkg['versions']) && is_array($pkg['versions'])) {
                    foreach ($pkg['versions'] as $metadata) {
                        if (isset($metadata['name'], $metadata['source']) && ($this->filter === null || \preg_match($this->filter, $metadata['name']))) {
                            $packages[$metadata['name']] = $metadata;
                        }
                    }
                }
            }

            return $packages;
        }

        if (isset($data['packages'])) {
            foreach ($data['packages'] as $package => $versions) {
                foreach ($versions as $version => $metadata) {
                    if (isset($metadata['name'], $metadata['source']) && ($this->filter === null || \preg_match($this->filter, $metadata['name']))) {
                        $packages[$metadata['name']] = $metadata;
                    }
                }
            }
        }

        if (isset($data['includes']) && \is_array($data['includes'])) {
            foreach ($data['includes'] as $include => $metadata) {
                $includedData = $this->fetchFile($include);
                $packages = array_merge($packages, $this->loadIncludes($includedData));
                if (count($packages) > $this->limitSize) {
                    break;
                }
            }
        }

        return $packages;
    }

    public function lookupAllProviders(): iterable
    {
        $sum = 0;
        if ($this->option->getRootProviders()) {
            $providers = $this->option->getRootProviders();
            foreach ($providers as $packageName => $data) {
                if (null !== $this->filter && !preg_match($this->filter, $packageName)) {
                    unset($providers[$packageName]);
                }
            }

            $sum += count($providers);

            yield $providers;
        }

        foreach ($this->option->getProviderIncludes(true) as $provider) {
            if ($sum > $this->limitSize) {
                break;
            }
            $content = $this->fetchFile($provider);
            $providers = $content['providers'] ?? [];
            foreach ($providers as $packageName => $data) {
                if (null !== $this->filter && !preg_match($this->filter, $packageName)) {
                    unset($providers[$packageName]);
                }
            }

            $sum += count($providers);
            yield $providers;
        }
    }

    protected function initComposer(): ProxyOptions
    {
        return $this->option->withRoot($this->fetchFile('packages.json'));
    }

    protected function fetchFile(string $filename): array
    {
        $response = $this->http->get($this->option->getUrl($filename));
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException("Unable to download $filename from {$this->option->getUrl()}. Error: {$response->getBody()}");
        }

        return $response->decodeJson();
    }
}
