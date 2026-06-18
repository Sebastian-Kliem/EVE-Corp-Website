<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:install',
    description: 'Runs initial installation tasks including database creation, migrations, and SDE download.',
)]
class AppInstallCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('WH-Toolbox Installation');

        $application = $this->getApplication();
        if (!$application) {
            $io->error('Application instance not found.');
            return Command::FAILURE;
        }

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
            $sdeUpdateCommand = $application->find('app:sde:update');
            $sdeUpdateInput = new ArrayInput([
                '--force' => true, // Force download during initial install
            ]);
            $sdeUpdateInput->setInteractive(false);
            $sdeUpdateCommand->run($sdeUpdateInput, $output);
        } catch (\Exception $e) {
            $io->error('SDE download/update failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // 4. Create Admin User
        $io->section('Step 4: Create Admin User');
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

            try {
                $createUserCommand = $application->find('app:create-user');
                $createUserInput = new ArrayInput([
                    'username' => $username,
                    'password' => $password,
                    'role' => 'ROLE_ADMIN',
                ]);
                $createUserCommand->run($createUserInput, $output);
            } catch (\Exception $e) {
                $io->error('Failed to create admin user: ' . $e->getMessage());
                return Command::FAILURE;
            }
        } else {
            $io->note('Non-interactive mode: Skipping admin user creation.');
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

        $io->newLine();
        $io->success('WH-Toolbox has been installed successfully!');
        
        return Command::SUCCESS;
    }
}
