<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * app:user:create — the only supported way to bootstrap an account on a fresh
 * deployment (there is no user-management endpoint).
 */
final class CreateUserCommandTest extends KernelTestCase
{
    use ResetDatabase;

    private function tester(): CommandTester
    {
        // self::$kernel is only populated by bootKernel(); getContainer() boots
        // lazily, so a test that asks for the tester first would pass null here.
        $application = new Application(self::bootKernel());
        $command = $application->find('app:user:create');
        $command->setApplication($application);

        return new CommandTester($command);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    public function testCreatesAUserWithAUsablePasswordHash(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute([
            'username' => 'bootstrap_admin',
            '--role' => 'ADMIN',
            '--password' => 'correct-horse',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringNotContainsString('correct-horse', $tester->getDisplay(), 'the password must never be echoed');

        $em = $this->em();
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'bootstrap_admin']);

        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserRole::ADMIN, $user->getRole());
        self::assertNotSame('correct-horse', $user->getPassword(), 'the stored value must be a hash');
        self::assertTrue(
            self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'correct-horse'),
        );
    }

    public function testRejectsAnUnknownRole(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(['username' => 'someone', '--role' => 'SUPERUSER', '--password' => 'x']);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('Unknown role', $tester->getDisplay());
    }

    public function testRefusesADuplicateUsername(): void
    {
        $em = $this->em();
        $existing = new User('taken', UserRole::OPERATOR);
        $existing->setPassword('hash');
        $em->persist($existing);
        $em->flush();

        $tester = $this->tester();
        $exit = $tester->execute(['username' => 'taken', '--password' => 'x']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }
}
