<?php

declare(strict_types=1);

namespace Packeton\Cron;

use Okvpn\Bundle\CronBundle\Loader\ScheduleLoaderInterface;
use Okvpn\Bundle\CronBundle\Model\ScheduleEnvelope;
use Packeton\Mirror\Model\ProxyOptions;
use Packeton\Mirror\ProxyRepositoryRegistry;
use Packeton\Mirror\RemoteProxyRepository;
use Okvpn\Bundle\CronBundle\Model;

class MirrorCronLoader implements ScheduleLoaderInterface
{
    public function __construct(private readonly ProxyRepositoryRegistry $registry)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getSchedules(array $options = []): iterable
    {
        if (!\array_intersect($options['groups'] ?? ['default'], ['default', 'mirror'])) {
            return;
        }

        foreach ($this->registry->getAllRepos() as $name => $repo) {
            if ($repo instanceof RemoteProxyRepository) {
                $repo->resetProxyOptions();
                $config = $repo->getConfig();
                $expr = '@random ' . $this->getSyncInterval($config);

                yield new ScheduleEnvelope(
                    'sync:mirrors',
                    new Model\ScheduleStamp($expr),
                    new WorkerStamp(asJob: true, hash: $config->reference()),
                    new Model\ArgumentsStamp(['mirror' => $name,])
                );
            }
        }
    }

    private function getSyncInterval(ProxyOptions $config): int
    {
        return $config->getSyncInterval() ?? match (true) {
            $config->isLazy() && $config->getV2SyncApi() => 900,
            $config->isLazy() && $config->hasV2Api() => 1800,
            $config->isLazy() => 7200,
            default => 86400,
        };
    }
}
