<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

use Composer\Console\HtmlOutputFormatter;
use Composer\Factory;
use Composer\IO\BufferIO;
use Packeton\Attribute\AsWorker;
use Packeton\Entity\Job;
use Packeton\Exception\SignalException;
use Packeton\Mirror\ProxyRepositoryRegistry;
use Packeton\Mirror\RemoteProxyRepository;
use Psr\Log\LoggerInterface;
use Seld\Signal\SignalHandler;
use Symfony\Component\Console\Output\OutputInterface;

#[AsWorker('sync:mirrors')]
class SyncMirrorWorker
{
    public function __construct(
        private readonly ProxyRepositoryRegistry $registry,
        private readonly RemoteSyncProxiesFacade $syncFacade,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Job $job, SignalHandler $signal)
    {
        $arguments = $job->getPayload();

        if (!isset($arguments['mirror']) || !$this->registry->hasRepository($arguments['mirror'])) {
            return ['message' => 'Repo not found', 'status' => Job::STATUS_COMPLETED,];
        }

        /** @var RemoteProxyRepository $repo */
        $repo = $this->registry->getRepository($arguments['mirror']);
        if (!$repo instanceof RemoteProxyRepository) {
            return [
                'status' => Job::STATUS_FAILED,
                'message' => 'Only RemoteProxyRepository allowed for synchronization',
            ];
        }

        $io = new BufferIO('', OutputInterface::VERBOSITY_VERY_VERBOSE, new HtmlOutputFormatter(Factory::createAdditionalStyles()));

        try {
            $stats = $this->syncFacade->sync($repo, $io, $arguments['flags'] ?? 0, $signal);
        } catch (SignalException $e) {
            $this->logger->info($e->getMessage());
            return [
                'status' => Job::STATUS_COMPLETED,
                'message' => 'Job manually interrupted by signal',
            ];

        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), ['e' => $e]);

            return [
                'status' => Job::STATUS_FAILED,
                'message' => 'Update of mirror ' . $repo->getUrl() . ' errored. ' . $e->getMessage(),
                'details' => '<pre>'.$io->getOutput().'</pre>',
                'exception' => $e,
            ];
        }

        $repo->setStats($stats);

        return [
            'status' => Job::STATUS_COMPLETED,
            'message' => 'Update of mirror '.$repo->getUrl().' complete',
            'details' => '<pre>'.$io->getOutput().'</pre>'
        ];
    }
}
