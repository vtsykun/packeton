<?php declare(strict_types=1);

namespace Packeton\Command;

use Packeton\Service\QueueWorker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('packagist:run-workers', description: 'Run queue workers service')]
class RunWorkersCommand extends Command
{
    public function __construct(
        protected LoggerInterface $logger,
        protected QueueWorker $queueWorker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('messages', null, InputOption::VALUE_OPTIONAL, 'Amount of messages to process before exiting', 5000)
            ->addOption('execute-once', null, InputOption::VALUE_NONE);

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->notice('Worker started successfully');

        $this->queueWorker->setOutput($output);
        $this->queueWorker->processMessages((int) $input->getOption('messages'), (bool) $input->getOption('execute-once'));
        $this->queueWorker->setOutput();

        $this->logger->notice('Worker exiting successfully');

        return 0;
    }
}
