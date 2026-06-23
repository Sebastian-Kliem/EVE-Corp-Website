<?php

namespace App\Command;

use App\Entity\CronJob;
use App\Service\Cron\CronTaskInterface;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

#[AsCommand(
    name: 'app:cron:run',
    description: 'Runs scheduled tasks defined in the database.',
)]
class CronRunCommand extends Command
{
    private array $taskRegistry = [];

    /**
     * @param iterable<CronTaskInterface> $tasks
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[TaggedIterator('app.cron_task')] iterable $tasks
    ) {
        parent::__construct();

        foreach ($tasks as $task) {
            $this->taskRegistry[$task->getCommandName()] = $task;
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        // 1. Auto-seed default cron jobs if database is empty or missing them
        $this->seedDefaultJobs();

        // 2. Fetch all active cron jobs
        $cronJobRepository = $this->entityManager->getRepository(CronJob::class);
        $activeJobs = $cronJobRepository->findBy(['isActive' => true]);

        if (empty($activeJobs)) {
            $io->note('No active cron jobs found.');
            return Command::SUCCESS;
        }

        foreach ($activeJobs as $job) {
            // If nextRunAt is null, initialize it and skip execution for this turn (or run if now is past it)
            if ($job->getNextRunAt() === null) {
                try {
                    $cron = new CronExpression($job->getCronExpression());
                    $nextRun = \DateTimeImmutable::createFromInterface($cron->getNextRunDate());
                    $job->setNextRunAt($nextRun);
                    $this->entityManager->flush();
                } catch (\Exception $e) {
                    $io->error(sprintf('Invalid cron expression for job "%s": %s', $job->getName(), $e->getMessage()));
                }
                continue;
            }

            // Check if job is due
            if ($now >= $job->getNextRunAt()) {
                $io->info(sprintf('Executing cron job: %s (%s)', $job->getName(), $job->getCommand()));
                
                $commandName = $job->getCommand();
                if (!isset($this->taskRegistry[$commandName])) {
                    $errorMsg = sprintf('Registered command service "%s" not found in container.', $commandName);
                    $io->warning($errorMsg);
                    
                    $job->setLastStatus('error');
                    $job->setLastError($errorMsg);
                    $job->setLastRunAt($now);
                    $this->updateNextRunAt($job, $now);
                    $this->entityManager->flush();
                    continue;
                }

                // Update nextRunAt immediately to prevent concurrent runs
                $this->updateNextRunAt($job, $now);
                $this->entityManager->flush();

                $task = $this->taskRegistry[$commandName];
                
                $startTime = microtime(true);
                try {
                    $task->execute();
                    
                    $executionTime = microtime(true) - $startTime;
                    
                    $job->setLastStatus('success');
                    $job->setLastError(null);
                    $job->setLastExecutionTime($executionTime);
                    
                    $io->success(sprintf('Job "%s" finished successfully in %.2f seconds.', $job->getName(), $executionTime));
                } catch (\Exception $e) {
                    $executionTime = microtime(true) - $startTime;
                    
                    $job->setLastStatus('error');
                    $job->setLastError($e->getMessage() . "\n" . $e->getTraceAsString());
                    $job->setLastExecutionTime($executionTime);
                    
                    $io->error(sprintf('Job "%s" failed after %.2f seconds: %s', $job->getName(), $executionTime, $e->getMessage()));
                }

                $job->setLastRunAt($now);
                $this->entityManager->flush();
            }
        }

        return Command::SUCCESS;
    }

    private function updateNextRunAt(CronJob $job, \DateTimeImmutable $now): void
    {
        try {
            $cron = new CronExpression($job->getCronExpression());
            // Calculate the next execution time starting from now
            $nextRun = \DateTimeImmutable::createFromInterface($cron->getNextRunDate($now));
            $job->setNextRunAt($nextRun);
        } catch (\Exception $e) {
            $job->setLastError($job->getLastError() . "\nFailed to calculate next run date: " . $e->getMessage());
        }
    }

    private function seedDefaultJobs(): void
    {
        $repo = $this->entityManager->getRepository(CronJob::class);
        
        $defaultJobs = [
            [
                'name' => 'Charakter-Daten synchronisieren (Wallet & Inventar)',
                'command' => 'character:sync-wallet-assets',
                'expression' => '*/10 * * * *', // every 10 minutes
            ],
            [
                'name' => 'Charakter-Bergbaudaten synchronisieren (Mining Ledger)',
                'command' => 'character:sync-mining-ledger',
                'expression' => '30 * * * *', // every hour at minute 30
            ],
            [
                'name' => 'Charakter-Industrieaufträge synchronisieren (Industry Jobs)',
                'command' => 'character:sync-industry-jobs',
                'expression' => '*/15 * * * *', // every 15 minutes
            ],
            [
                'name' => 'Charakter-Verträge synchronisieren (Contracts)',
                'command' => 'character:sync-contracts',
                'expression' => '*/20 * * * *', // every 20 minutes
            ],
            [
                'name' => 'Struktur-Cache aktualisieren (Spieler-Strukturen)',
                'command' => 'structure:update-cache',
                'expression' => '0 */6 * * *', // every 6 hours
            ]
        ];

        foreach ($defaultJobs as $default) {
            $job = $repo->findOneBy(['command' => $default['command']]);
            if (!$job) {
                $job = new CronJob();
                $job->setName($default['name']);
                $job->setCommand($default['command']);
                $job->setCronExpression($default['expression']);
                $job->setIsActive(true);
                
                try {
                    $cron = new CronExpression($default['expression']);
                    $nextRun = \DateTimeImmutable::createFromInterface($cron->getNextRunDate());
                    $job->setNextRunAt($nextRun);
                } catch (\Exception $e) {
                    // Ignore, fall back to null
                }

                $this->entityManager->persist($job);
            }
        }

        $this->entityManager->flush();
    }
}
