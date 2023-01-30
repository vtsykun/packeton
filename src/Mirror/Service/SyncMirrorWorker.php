<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

use Packeton\Attribute\AsWorker;
use Packeton\Mirror\ProxyRepositoryRegistry;
use Packeton\Mirror\RemoteProxyRepository;

#[AsWorker('sync:mirrors')]
class SyncMirrorWorker
{
    public function __construct(
        private readonly ProxyRepositoryRegistry $registry,
        private readonly RemoteSyncProxiesFacade $syncFacade
    ) {
    }

    public function __invoke(array $arguments = [])
    {
        if (!isset($arguments['mirror']) || !$this->registry->hasRepository($arguments['mirror'])) {
            return ['message' => 'Repo not found'];
        }

        /** @var RemoteProxyRepository $repo */
        $repo = $this->registry->getRepository($arguments['mirror']);
        $stats = $this->syncFacade->sync($repo);

        $repo->setStats($stats);
        return [];
    }
}
