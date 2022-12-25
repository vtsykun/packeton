<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Command;

use FOS\UserBundle\Model\UserManagerInterface;
use FOS\UserBundle\Util\UserManipulator;
use Packagist\WebBundle\Entity\User;
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

    private $userManipulator;

    private $userManager;

    public function __construct(UserManipulator $userManipulator, UserManagerInterface $userManager)
    {
        parent::__construct();

        $this->userManipulator = $userManipulator;
        $this->userManager = $userManager;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName(self::$defaultName)
            ->addArgument('username', InputArgument::REQUIRED, 'The username')
            ->addOption('email', null, InputOption::VALUE_OPTIONAL, 'The email')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'The password')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Is admin user?')
            ->setDescription('Change or create user');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $username = $input->getArgument('username');

        /** @var User $user */
        if ($user = $this->userManager->findUserByUsername($username)) {
            if ($input->getOption('admin')) {
                $this->userManipulator->addRole($username, 'ROLE_ADMIN');
            }
            if ($password = $input->getOption('password')) {
                $this->userManipulator->changePassword($username, $password);
            }

            $output->writeln("User $username was updated successfully");
            return;
        }

        $password = $input->getOption('password') ?: $username;
        $email = $input->getOption('email') ?: $username . '@example.com';
        $this->userManipulator->create($username, $password, $email, true, $input->getOption('admin'));
        $this->userManipulator->addRole($username, 'ROLE_ADMIN');

        $output->writeln("User $username was created successfully");
    }
}
