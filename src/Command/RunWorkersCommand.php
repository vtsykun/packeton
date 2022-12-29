<?php declare(strict_types=1);

namespace Packeton\Command;

use Packeton\Service\QueueWorker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RunWorkersCommand extends Command
{
    protected static $defaultName = 'packagist:run-workers';

    public function __construct(
        protected LoggerInterface $logger,
        protected QueueWorker $queueWorker,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Run worker services')
            ->addOption('messages', null, InputOption::VALUE_OPTIONAL, 'Amount of messages to process before exiting', 5000);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->notice('Worker started successfully');

        $this->queueWorker->processMessages((int) $input->getOption('messages'));

        $this->logger->notice('Worker exiting successfully');

        return 0;
    }
}
