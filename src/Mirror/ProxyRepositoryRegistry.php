<?php

declare(strict_types=1);

namespace Packeton\Mirror;

use Packeton\Mirror\Model\ProxyRepositoryInterface;
use Psr\Container\ContainerInterface;

class ProxyRepositoryRegistry
{
    public function __construct(
        protected ContainerInterface $container,
        protected array $repos
    ) {}

    public function getRepository(string $name): ProxyRepositoryInterface
    {
        if (!$this->container->has($name)) {
            throw new \InvalidArgumentException("Repository $name does not exists.");
        }

        return $this->container->get($name);
    }

    public function hasRepository(string $name)
    {
        return $this->container->get($name);
    }

    /**
     * @return iterable|ProxyRepositoryInterface[]
     */
    public function getAllRepos(): iterable
    {
        foreach ($this->repos as $repo) {
            yield $repo => $this->container->get($repo);
        }
    }

    public function getAllNames(): array
    {
        return $this->repos;
    }
}
