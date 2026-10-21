<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates an account with a hashed password.
 *
 * The application has no user-management endpoint and no fixtures outside the
 * test environment, so before this command there was no supported way to create
 * the first account on a fresh deployment — the only options were a manual SQL
 * insert or a hand-written script. It is also what the end-to-end suite uses to
 * seed its three role accounts.
 */
#[AsCommand(name: 'app:user:create', description: 'Create a CoffeeStock user with a hashed password')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Unique username (also the login identifier)')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'One of OPERATOR, MANAGER, ADMIN', 'OPERATOR')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password; omit to be prompted without echo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = trim((string) $input->getArgument('username'));
        if ('' === $username) {
            $io->error('The username must not be empty.');

            return Command::INVALID;
        }

        $role = UserRole::tryFrom(strtoupper((string) $input->getOption('role')));
        if (null === $role) {
            $io->error('Unknown role. Use one of: OPERATOR, MANAGER, ADMIN.');

            return Command::INVALID;
        }

        if (null !== $this->users->findByUsername($username)) {
            $io->error(sprintf('A user named "%s" already exists.', $username));

            return Command::FAILURE;
        }

        $password = $input->getOption('password');
        if (null === $password) {
            $password = $io->askHidden('Password');
        }
        if (!is_string($password) || '' === $password) {
            $io->error('The password must not be empty.');

            return Command::INVALID;
        }

        $user = new User($username, $role);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        // The password is never echoed back.
        $io->success(sprintf('Created %s user "%s" (id %s).', $role->value, $username, (string) $user->getId()));

        return Command::SUCCESS;
    }
}
