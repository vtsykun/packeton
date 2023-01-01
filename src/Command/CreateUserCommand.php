<?php

declare(strict_types=1);

namespace Packeton\Command;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\User;
use Packeton\Security\Provider\UserProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Simplify create and update user for docker.
 */
class CreateUserCommand extends Command
{
    protected static $defaultName = 'packagist:user:manager';

    public function __construct(
        protected UserProvider $userProvider,
        protected UserPasswordHasherInterface $passwordHasher,
        protected ManagerRegistry $registry,
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
            ->addOption('enabled', null, InputOption::VALUE_OPTIONAL, 'Set user enable/disable, example --enabled=1, --enabled=0')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'The password')
            ->addOption('add-role', null, InputOption::VALUE_OPTIONAL, 'Add the user role, example --add-role=ROLE_MAINTAINER')
            ->addOption('remove-role', null, InputOption::VALUE_OPTIONAL, 'Remove the user role, example --remove-role=ROLE_MAINTAINER')
            ->addOption('only-if-not-exists', null, InputOption::VALUE_NONE, 'Only create a new user without update existing (use in docker init)')
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
        $user = null;
        try {
            $user = $this->userProvider->loadUserByIdentifier($username);
        } catch (\Exception) {}

        $manager = $this->registry->getManager();
        if ($user instanceof User) {
            if ($input->getOption('only-if-not-exists')) {
                return 0;
            }

            if ($input->getOption('admin')) {
                $user->addRole('ROLE_ADMIN');
            }

            if ($password = $input->getOption('password')) {
                $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            }

            if ($input->hasOption('enabled')) {
                $user->setEnabled((bool) $input->getOption('enabled'));
            }

            $this->updateRoles($user, $input, $output);

            $manager->flush();
            $output->writeln("User $username was updated successfully");
            return 0;
        }

        $password = $input->getOption('password') ?: hash('sha512', random_bytes(50));

        $user = new User();
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $user->setUsername($username);
        $user->setEnabled(true);
        $user->setEmail($input->getOption('email') ?: $username . '@example.com');
        $user->generateApiToken();

        if ($input->getOption('admin')) {
            $user->addRole('ROLE_ADMIN');
        }

        $this->updateRoles($user, $input, $output);

        $manager->persist($user);
        $manager->flush();

        $output->writeln("User $username was created successfully, api token: {$user->getApiToken()}");

        return 0;
    }

    public function updateRoles(User $user, InputInterface $input, OutputInterface $output)
    {
        if ($role = $input->getOption('add-role')) {
            $user->addRole($role);
            $output->writeln("Added role $role");
        }

        if ($role = $input->getOption('remove-role')) {
            $user->removeRole($role);
            $output->writeln("Unset role $role ");
        }
    }
}
