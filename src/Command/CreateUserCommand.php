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
    name: 'app:create-user',
    description: 'Creates a new user.',
)]
class CreateUserCommand extends Command
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
            ->addArgument('password', InputArgument::REQUIRED, 'The password of the user.')
            ->addArgument('role', InputArgument::OPTIONAL, 'The role of the user (ROLE_RECRUIT, ROLE_MEMBER, ROLE_OFFICER, ROLE_CEO, ROLE_ADMIN).', 'ROLE_RECRUIT')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = $input->getArgument('username');
        $password = $input->getArgument('password');
        $role = $input->getArgument('role');

        // Validate if the provided role is allowed
        $validRoles = ['ROLE_RECRUIT', 'ROLE_MEMBER', 'ROLE_OFFICER', 'ROLE_CEO', 'ROLE_ADMIN'];
        if (!in_array($role, $validRoles, true)) {
            $io->error(sprintf('Invalid role "%s". Valid roles are: %s', $role, implode(', ', $validRoles)));
            return Command::INVALID;
        }

        $user = new User();
        $user->setUsername($username);
        $user->setPassword(
            $this->passwordHasher->hashPassword($user, $password)
        );
        $user->setRoles([$role]);



        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('User %s was created successfully!', $username));

        return Command::SUCCESS;
    }
}
