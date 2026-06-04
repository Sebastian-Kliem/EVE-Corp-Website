<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:reset-password',
    description: 'Resets the password of a user (ideal for emergencies or when a website admin gets locked out).',
)]
class ResetPasswordCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'The username of the user.')
            ->addArgument('password', InputArgument::OPTIONAL, 'The new password. If omitted, a random temporary password will be generated.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = trim($input->getArgument('username'));
        $password = $input->getArgument('password');

        // Find user
        $userRepository = $this->entityManager->getRepository(User::class);
        $user = $userRepository->findOneBy(['username' => $username]);

        if (!$user) {
            $io->error(sprintf('User with username "%s" not found.', $username));
            return Command::FAILURE;
        }

        // Determine if password needs to be generated
        $isGenerated = false;
        if (empty($password)) {
            $password = 'Keepers-' . random_int(100000, 999999);
            $isGenerated = true;
        }

        // Hash and apply password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);
        
        $this->entityManager->flush();

        $io->newLine();
        $io->success(sprintf('Password for user %s successfully reset!', $username));

        if ($isGenerated) {
            $io->section('⚠️ Temporäres Passwort generiert:');
            $io->text('Teile dieses Passwort dem Benutzer mit. Er/Sie sollte es nach dem Login sofort ändern.');
            $io->newLine();
            $io->caution(sprintf('Passwort: %s', $password));
        } else {
            $io->text('Das angegebene Wunschpasswort wurde erfolgreich gesetzt und gehasht.');
        }

        return Command::SUCCESS;
    }
}
