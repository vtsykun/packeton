<?php

declare(strict_types=1);

namespace Packeton\Command;

use Packeton\Entity\User;
use Packeton\Security\Provider\UserProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Simplify create and update user for docker.
 */
class CreateUserCommand extends Command
{
    protected static $defaultName = 'packagist:user:manager';

    public function __construct(
        protected UserProvider $userProvider
    ) {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'The username')
            ->addOption('email', null, InputOption::VALUE_OPTIONAL, 'The email')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'The password')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Is admin user?')
            ->setDescription('Change or create user');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $input->getArgument('username');

        /** @var User $user */
        if ($user = $this->userProvider->loadUserByIdentifier($username)) {
            if ($input->getOption('admin')) {
                $this->userManipulator->addRole($username, 'ROLE_ADMIN');
            }
            if ($password = $input->getOption('password')) {
                $this->userManipulator->changePassword($username, $password);
            }

            $output->writeln("User $username was updated successfully");
            return 0;
        }

        $password = $input->getOption('password') ?: $username;
        $email = $input->getOption('email') ?: $username . '@example.com';
        $this->userManipulator->create($username, $password, $email, true, $input->getOption('admin'));
        $this->userManipulator->addRole($username, 'ROLE_ADMIN');

        $output->writeln("User $username was created successfully");

        return 0;
    }
}
