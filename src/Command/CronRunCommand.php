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
use Symfony\Component\HttpKernel\KernelInterface;

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
        private readonly KernelInterface $kernel,
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

        $logFile = $this->kernel->getProjectDir() . '/var/log/cron.log';
        $writeLog = function(string $message, string $level = 'INFO') use ($logFile) {
            $formatted = sprintf("[%s] [%s] [Scheduler] %s\n", (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $level, $message);
            try {
                $logDir = dirname($logFile);
                if (!is_dir($logDir)) {
                    mkdir($logDir, 0777, true);
                }
                file_put_contents($logFile, $formatted, FILE_APPEND);
            } catch (\Exception $e) {
                // Ignore
            }
        };

        $writeLog('Starte Cronjob-Runner Ausführung...');

        // 1. Auto-seed default cron jobs if database is empty or missing them
        $this->seedDefaultJobs();

        // 2. Fetch all active cron jobs
        $cronJobRepository = $this->entityManager->getRepository(CronJob::class);
        $activeJobs = $cronJobRepository->findBy(['isActive' => true]);

        if (empty($activeJobs)) {
            $io->note('No active cron jobs found.');
            $writeLog('Keine aktiven Cronjobs in der Datenbank gefunden.');
            return Command::SUCCESS;
        }

        $dueJobsCount = 0;
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
                    $writeLog(sprintf('Ungültiger Cron-Ausdruck für Job "%s": %s', $job->getName(), $e->getMessage()), 'ERROR');
                }
                continue;
            }

            // Check if job is due
            if ($now >= $job->getNextRunAt()) {
                $dueJobsCount++;
                $io->info(sprintf('Executing cron job: %s (%s)', $job->getName(), $job->getCommand()));
                $writeLog(sprintf('Führe Cronjob aus: %s (%s)', $job->getName(), $job->getCommand()));
                
                $commandName = $job->getCommand();
                if (!isset($this->taskRegistry[$commandName])) {
                    $errorMsg = sprintf('Registered command service "%s" not found in container.', $commandName);
                    $io->warning($errorMsg);
                    $writeLog($errorMsg, 'ERROR');
                    
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
                    $writeLog(sprintf('Job "%s" erfolgreich beendet in %.2f Sekunden.', $job->getName(), $executionTime));
                } catch (\Exception $e) {
                    $executionTime = microtime(true) - $startTime;
                    
                    $job->setLastStatus('error');
                    $job->setLastError($e->getMessage() . "\n" . $e->getTraceAsString());
                    $job->setLastExecutionTime($executionTime);
                    
                    $io->error(sprintf('Job "%s" failed after %.2f seconds: %s', $job->getName(), $executionTime, $e->getMessage()));
                    $writeLog(sprintf('Job "%s" fehlgeschlagen nach %.2f Sekunden: %s', $job->getName(), $executionTime, $e->getMessage()), 'ERROR');
                }

                $job->setLastRunAt($now);
                $this->entityManager->flush();
            }
        }

        if ($dueJobsCount === 0) {
            $writeLog('Keine Cronjobs fällig.');
        }

        $writeLog('Cronjob-Runner Ausführung beendet.');
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
                'expression' => '*/5 * * * *', // every 5 minutes
            ],
            [
                'name' => 'Charakter-Bergbaudaten synchronisieren (Mining Ledger)',
                'command' => 'character:sync-mining-ledger',
                'expression' => '*/5 * * * *', // every 5 minutes
            ],
            [
                'name' => 'Charakter-Industrieaufträge synchronisieren (Industry Jobs)',
                'command' => 'character:sync-industry-jobs',
                'expression' => '*/5 * * * *', // every 5 minutes
            ],
            [
                'name' => 'Charakter-Verträge synchronisieren (Contracts)',
                'command' => 'character:sync-contracts',
                'expression' => '*/5 * * * *', // every 5 minutes
            ],
            [
                'name' => 'Struktur-Cache aktualisieren (Spieler-Strukturen)',
                'command' => 'structure:update-cache',
                'expression' => '0 */6 * * *', // every 6 hours
            ],
            [
                'name' => 'Charakter-PI-Daten synchronisieren (Planetary Industry)',
                'command' => 'character:sync-pi',
                'expression' => '0 */2 * * *', // every 2 hours
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
            } else {
                // If job exists but has a different expression, update it automatically
                if ($job->getCronExpression() !== $default['expression']) {
                    $job->setCronExpression($default['expression']);
                    try {
                        $cron = new CronExpression($default['expression']);
                        $nextRun = \DateTimeImmutable::createFromInterface($cron->getNextRunDate());
                        $job->setNextRunAt($nextRun);
                    } catch (\Exception $e) {
                        // Ignore
                    }
                }
            }
        }

        $this->entityManager->flush();
    }
}
