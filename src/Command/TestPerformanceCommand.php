<?php

namespace App\Command;

use App\Entity\User;
use App\Service\PerformanceEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test:performance',
    description: 'Tests the PerformanceEngine daily calculations for a given user.',
)]
class TestPerformanceCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PerformanceEngine $performanceEngine
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::OPTIONAL, 'The username of the user', 'sebastian');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = $input->getArgument('username');

        $userRepo = $this->entityManager->getRepository(User::class);
        $user = $userRepo->findOneBy(['username' => $username]) ?? $userRepo->findOneBy(['username' => 'admin']);

        if (!$user) {
            $io->error(sprintf('User "%s" not found in database.', $username));
            return Command::FAILURE;
        }

        $io->title(sprintf('Calculating Daily Performance for User: %s', $user->getUsername()));

        $data = $this->performanceEngine->calculateDailyPerformance($user);

        $io->success(sprintf('Calculated performance for %d days.', count($data)));

        foreach ($data as $date => $day) {
            $io->section(sprintf('Date: %s (Total: %s ISK)', $date, number_format($day['summary']['totalValue'], 2)));
            
            $io->text('Breakdown by Category:');
            foreach ($day['summary']['byCategory'] as $cat => $val) {
                if ($val > 0) {
                    $io->text(sprintf('  - %s: %s ISK', $cat, number_format($val, 2)));
                }
            }

            $io->text('Top 5 details:');
            $details = array_slice($day['details'], 0, 5);
            $rows = [];
            foreach ($details as $det) {
                $rows[] = [
                    $det['character'],
                    $det['category'],
                    $det['typeName'],
                    $det['quantity'],
                    number_format($det['price'], 2) . ' ISK',
                    number_format($det['totalValue'], 2) . ' ISK',
                ];
            }
            $io->table(
                ['Character', 'Category', 'Item', 'Qty', 'Price', 'Total'],
                $rows
            );
        }

        return Command::SUCCESS;
    }
}
