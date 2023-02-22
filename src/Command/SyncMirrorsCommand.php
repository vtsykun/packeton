<?php

declare(strict_types=1);

namespace Packeton\Command;

use Composer\IO\ConsoleIO;
use Packeton\Mirror\RemoteProxyRepository;
use Packeton\Mirror\ProxyRepositoryRegistry;
use Packeton\Mirror\Service\RemoteSyncProxiesFacade;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('packagist:sync:mirrors', 'Sync mirror repository proxy.')]
class SyncMirrorsCommand extends Command
{
    public function __construct(
        protected ProxyRepositoryRegistry $repoRegistry,
        protected RemoteSyncProxiesFacade $syncFacade
    ) {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('mirror', InputArgument::OPTIONAL, 'Mirror name in config file.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Remote all data and sync again');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->repoRegistry->getAllNames()) {
            throw new LogicException('Mirror proxies is not setup for the application, please see configuration packeton->mirrors');
        }

        $io = new SymfonyStyle($input, $output);
        if (empty($mirror = $input->getArgument('mirror'))) {
            $mirror = $io->choice('Select the mirror for sync', $this->repoRegistry->getAllNames());
        }

        $repo = $this->repoRegistry->getRepository($mirror);
        if ($repo instanceof RemoteProxyRepository) {
            $io->info("Start sync: {$repo->getUrl()}");
            return $this->syncRepo($repo, $input, $output);
        } else {
            $io->warning('You can able to sync only remote composer repositories');
            return 1;
        }
    }

    private function syncRepo(RemoteProxyRepository $repo, InputInterface $input, OutputInterface $output): int
    {
        $io = new ConsoleIO($input, $output, new HelperSet());
        $flags = $input->getOption('force') ? RemoteSyncProxiesFacade::FULL_RESET : RemoteSyncProxiesFacade::UI_RESET;
        $stats = $this->syncFacade->sync($repo, $io, $flags);
        $repo->setStats($stats);

        return 0;
    }
}
