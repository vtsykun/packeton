<?php

declare(strict_types=1);

namespace Packeton\Command;

use Doctrine\Persistence\ManagerRegistry;
use Packeton\Entity\User;
use Packeton\Security\JWTUserManager;
use Packeton\Security\Provider\UserProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\Input as SymfonyInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Simplify create and update user for docker.
 */
class UserManagerCommand extends Command
{
    protected static $defaultName = 'packagist:user:manager';

    public function __construct(
        protected UserProvider $userProvider,
        protected UserPasswordHasherInterface $passwordHasher,
        protected ManagerRegistry $registry,
        protected JWTUserManager $jwtUserManager
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
            ->addOption('email', null, InputOption::VALUE_OPTIONAL, 'Update/set email address')
            ->addOption('enabled', null, InputOption::VALUE_OPTIONAL, 'Set user enable/disable, example --enabled=1, --enabled=0')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'Update/set password')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Drop user from database.')
            ->addOption('show-token', null, InputOption::VALUE_NONE, 'Show api token.')
            ->addOption('regenerate-token', null, InputOption::VALUE_NONE, 'Regenerate standard API token.')
            ->addOption('token-format', null, InputOption::VALUE_OPTIONAL, 'Api token format: "jwt" or standard API.', 'api')
            ->addOption('add-role', null, InputOption::VALUE_OPTIONAL, 'Add the user role, example --add-role=ROLE_MAINTAINER')
            ->addOption('remove-role', null, InputOption::VALUE_OPTIONAL, 'Remove the user role, example --remove-role=ROLE_MAINTAINER')
            ->addOption('only-if-not-exists', null, InputOption::VALUE_NONE, 'Only create a new user without update existing (use in docker init)')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Add admin user role')
            ->setDescription('CLI user manager - create, get info, or update users');

        $this->setHelp(
<<<HELP
The <info>%command.name%</info> command create/update or show information about user.
Example usage:

    <info>%command.full_name% admin --email=admin@example.com --password=123456 --admin</info> Create admin user
    <info>%command.full_name% dev12100 --email=devnull@example.com --password --add-role=ROLE_MAINTAINER</info> Create maintainer.
    <info>%command.full_name% admin --show-token --token-format=jwt</info> Show JWT API token if setup JWT config.
HELP
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = $input->getArgument('username');

        /** @var User $user */
        $user = null;
        try {
            $user = $this->userProvider->loadUserByIdentifier($username);
        } catch (\Exception) {}

        if (!$this->hasAnyOptions($input)) {
            $io->warning("Command run without any option. A new empty user will be created");
            if (!$io->confirm('Do you want to continue?')) {
                return 0;
            }
        }

        if ($input->getOption('show-token')) {
            return $this->showTokenCommand($user, $input, $output);
        }

        if ($input->getOption('delete')) {
            return $this->dropUser($user, $input, $output);
        }

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

            if ($input->hasOption('enabled') && null !== $input->getOption('enabled')) {
                $user->setEnabled((bool) $input->getOption('enabled'));
            }

            $this->updateRoles($user, $input, $output);

            $manager->flush();
            $output->writeln("User $username was updated successfully");
            return 0;
        }

        if ($user instanceof UserInterface) {
            $io->warning("User already loaded from external user provider, but does not exists in database.");
            if (!$io->confirm('Are you want to create user in local database?')) {
                $io->comment('User creation is was canceled.');
                return 0;
            }
        }

        if ($input->hasOption('password')) {
            $io->writeln("Creating a user {$username}...");
            $password = $io->askHidden('Enter password');
            $input->setOption('password', $password);
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

    protected function updateRoles(User $user, InputInterface $input, OutputInterface $output)
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

    protected function dropUser(?UserInterface $user, InputInterface $input, OutputInterface $output)
    {
        if ($user instanceof User) {
            throw new LogicException('User not found or not exists in database');
        }

        $manager = $this->registry->getManager();
        $manager->remove($user);
        $manager->flush();

        $output->writeln("<info>User was deleted. </info>");
        return 0;
    }

    protected function showTokenCommand(?UserInterface $user, InputInterface $input, OutputInterface $output)
    {
        if (null === $user) {
            throw new LogicException('User not found.');
        }

        $token = false;
        if ($input->getOption('token-format') === 'jwt') {
            try {
                $token = $this->jwtUserManager->createTokenForUser($user);
            } catch (\Exception $e) {
                $output->writeln("<error>JWT configuration is not setup: {$e->getMessage()}</error>");
                return 1;
            }
        } elseif ($user instanceof User) {
            $token = $user->getApiToken();
        }

        if (false === $token) {
            throw new LogicException('Standard API token supported only for database storage users. Use --token-format=jwt to show JWT format.');
        }

        $output->writeln("Token: $token");

        return 0;
    }

    protected function hasAnyOptions(InputInterface $input)
    {
        if ($input instanceof SymfonyInput) {
            $reflect = new \ReflectionClass(SymfonyInput::class);
            if (!$reflect->hasProperty('options')) {
                return true;
            }

            $prop = $reflect->getProperty('options');
            $prop->setAccessible(true);
            return (bool) $prop->getValue($input);
        }

        return true;
    }
}
