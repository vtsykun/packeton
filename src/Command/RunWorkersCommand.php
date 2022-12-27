<?php declare(strict_types=1);

namespace Packeton\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RunWorkersCommand extends Command
{
    protected static $defaultName = 'packagist:run-workers';

    protected function configure()
    {
        $this
            ->setDescription('Run worker services')
            ->addOption('messages', null, InputOption::VALUE_OPTIONAL, 'Amount of messages to process before exiting', 5000);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = $this->getContainer()->get('logger');
        $worker = $this->getContainer()->get('packagist.queue_worker');

        $logger->notice('Worker started successfully');
        $this->getContainer()->get('packagist.log_resetter')->reset();

        $worker->processMessages((int) $input->getOption('messages'));

        $logger->notice('Worker exiting successfully');

        return 0;
    }
}
