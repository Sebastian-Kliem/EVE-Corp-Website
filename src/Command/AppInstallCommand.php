<?php

namespace App\Command;

use App\Entity\EveCharacter;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:install',
    description: 'Runs initial installation tasks including database creation, migrations, and SDE download.',
)]
class AppInstallCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KernelInterface $kernel
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('WH-Toolbox Installation / Update');

        $application = $this->getApplication();
        if (!$application) {
            $io->error('Application instance not found.');
            return Command::FAILURE;
        }

        $projectDir = $this->kernel->getProjectDir();

        // 1. Create main database if it does not exist
        $io->section('Step 1: Creating Main Database (if not exists)');
        try {
            $dbCreateCommand = $application->find('doctrine:database:create');
            $dbCreateInput = new ArrayInput([
                '--if-not-exists' => true,
            ]);
            $dbCreateInput->setInteractive(false);
            $dbCreateCommand->run($dbCreateInput, $output);
        } catch (\Exception $e) {
            $io->error('Failed to create database: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // 2. Run Database Migrations
        $io->section('Step 2: Running Database Migrations');
        try {
            $migrateCommand = $application->find('doctrine:migrations:migrate');
            $migrateInput = new ArrayInput([
                '--no-interaction' => true,
            ]);
            $migrateInput->setInteractive(false);
            $migrateCommand->run($migrateInput, $output);
        } catch (\Exception $e) {
            $io->error('Migrations failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // 3. Download and import EVE SDE SQLite
        $io->section('Step 3: Initializing EVE Online SDE (Static Data Export)');
        try {
            $sdeFile = $projectDir . '/var/sde.sqlite';
            $sdeExists = file_exists($sdeFile);
            
            if ($sdeExists) {
                $io->text('SDE file already exists. Checking for updates...');
            } else {
                $io->text('SDE file does not exist. Initiating download...');
            }

            $sdeUpdateCommand = $application->find('app:sde:update');
            $sdeUpdateInput = new ArrayInput([
                '--force' => !$sdeExists, // Only force download if it does not exist
            ]);
            $sdeUpdateInput->setInteractive(false);
            $sdeUpdateCommand->run($sdeUpdateInput, $output);
        } catch (\Exception $e) {
            $io->error('SDE download/update failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // 4. Create Admin User
        $io->section('Step 4: Create Admin User');
        try {
            $userCount = $this->entityManager->getRepository(User::class)->count([]);
            if ($userCount > 0) {
                $io->note('Users already exist in the database. Skipping admin user creation.');
            } else {
                if ($input->isInteractive()) {
                    $username = $io->ask('Admin Username', 'admin', function ($value) {
                        if (empty($value)) {
                            throw new \RuntimeException('Username cannot be empty.');
                        }
                        return $value;
                    });

                    $password = $io->askHidden('Admin Password', function ($value) {
                        if (empty($value)) {
                            throw new \RuntimeException('Password cannot be empty.');
                        }
                        return $value;
                    });

                    $createUserCommand = $application->find('app:create-user');
                    $createUserInput = new ArrayInput([
                        'username' => $username,
                        'password' => $password,
                        'role' => 'ROLE_ADMIN',
                    ]);
                    $createUserCommand->run($createUserInput, $output);
                } else {
                    $io->note('Non-interactive mode: Skipping admin user creation.');
                }
            }
        } catch (\Exception $e) {
            $io->error('Failed to create admin user: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // 5. Seed Tracking Templates
        $io->section('Step 5: Seeding Tracking Templates');
        try {
            $seedCommand = $application->find('app:seed:tracking-templates');
            $seedInput = new ArrayInput([]);
            $seedInput->setInteractive(false);
            $seedCommand->run($seedInput, $output);
        } catch (\Exception $e) {
            $io->error('Template seeding failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // 6. Initialize Performance Cutoff Date for existing characters
        $io->section('Step 6: Initializing Performance Cutoff Dates');
        try {
            $characterRepo = $this->entityManager->getRepository(EveCharacter::class);
            $charactersWithoutCutoff = $characterRepo->findBy(['performanceCutoffDate' => null]);
            
            if (count($charactersWithoutCutoff) > 0) {
                $now = new \DateTimeImmutable();
                foreach ($charactersWithoutCutoff as $character) {
                    $character->setPerformanceCutoffDate($now);
                }
                $this->entityManager->flush();
                $io->success(sprintf('Initialized performance cutoff date to %s for %d characters.', $now->format('Y-m-d H:i:s'), count($charactersWithoutCutoff)));
            } else {
                $io->note('All characters already have a performance cutoff date set.');
            }
        } catch (\Exception $e) {
            $io->error('Failed to initialize performance cutoff dates: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->newLine();
        $io->success('WH-Toolbox has been installed/updated successfully!');
        
        return Command::SUCCESS;
    }
}
