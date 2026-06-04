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

#[AsCommand(
    name: 'app:promote-user',
    description: 'Promotes or sets roles for an existing user.',
)]
class PromoteUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'The username of the user to promote.')
            ->addArgument('role', InputArgument::REQUIRED, 'The new role (e.g. ROLE_RECRUIT, ROLE_MEMBER, ROLE_OFFICER, ROLE_CEO, ROLE_ADMIN).')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = $input->getArgument('username');
        $role = $input->getArgument('role');

        // Validate role
        $validRoles = ['ROLE_RECRUIT', 'ROLE_MEMBER', 'ROLE_OFFICER', 'ROLE_CEO', 'ROLE_ADMIN'];
        if (!in_array($role, $validRoles, true)) {
            $io->error(sprintf('Invalid role "%s". Valid roles are: %s', $role, implode(', ', $validRoles)));
            return Command::INVALID;
        }

        // Find user by username
        $userRepository = $this->entityManager->getRepository(User::class);
        $user = $userRepository->findOneBy(['username' => $username]);

        if (!$user) {
            $io->error(sprintf('User with username "%s" not found.', $username));
            return Command::FAILURE;
        }

        // Assign the role
        $user->setRoles([$role]);
        $this->entityManager->flush();

        $io->success(sprintf('User %s has been successfully assigned the role %s!', $username, $role));

        return Command::SUCCESS;
    }
}
