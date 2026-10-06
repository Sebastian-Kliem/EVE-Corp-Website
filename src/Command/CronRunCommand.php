<?php

namespace App\Command;

use App\Entity\CronJob;
use App\Service\Cron\CronErrorCollector;
use App\Service\Cron\CronLanes;
use App\Service\Cron\CronLogWriter;
use App\Service\Cron\CronTaskInterface;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:cron:run',
    description: 'Runs scheduled tasks defined in the database.',
)]
class CronRunCommand extends Command
{
    private array $taskRegistry = [];
    private EntityManagerInterface $entityManager;

    /**
     * @param iterable<CronTaskInterface> $tasks
     */
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        EntityManagerInterface $entityManager,
        private readonly \App\Service\Esi\EsiClient $esiClient,
        private readonly LockFactory $lockFactory,
        private readonly CronErrorCollector $errorCollector,
        private readonly CronLogWriter $cronLogWriter,
        #[AutowireIterator('app.cron_task')] iterable $tasks
    ) {
        parent::__construct();

        $this->entityManager = $entityManager;

        foreach ($tasks as $task) {
            $this->taskRegistry[$task->getCommandName()] = $task;
        }
    }

    protected function configure(): void
    {
        $this->addOption('job', null, InputOption::VALUE_OPTIONAL, 'Only execute a specific cron job command');
        $this->addOption('lane', null, InputOption::VALUE_REQUIRED, 'Only execute jobs of this lane (default, fast)', CronLanes::DEFAULT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();
        $jobOption = $input->getOption('job');
        $lane = (string)$input->getOption('lane');
        if (!CronLanes::isValid($lane)) {
            $io->error(sprintf('Unknown lane "%s".', $lane));
            return Command::INVALID;
        }

        // The fast lane runs every minute, keep its routine messages out of the info log
        $isQuietLane = $jobOption === null && $lane === CronLanes::FAST;
        $writeLog = function(string $message, string $level = 'INFO') use ($isQuietLane) {
            if ($isQuietLane && $level === 'INFO') {
                $level = 'DEBUG';
            }
            $this->cronLogWriter->write('[Scheduler] ' . $message, $level);
        };

        $writeLog('Starte Cronjob-Runner Ausführung...');

        if ($this->esiClient->isOffline($isQuietLane)) {
            $writeLog('ESI ist offline (Downtime oder Circuit Breaker aktiv). Ausführung aller Cronjobs übersprungen.', $isQuietLane ? 'DEBUG' : 'WARNING');
            $io->warning('ESI is offline. Skipping cron job execution.');
            $writeLog('Cronjob-Runner Ausführung beendet.');
            return Command::SUCCESS;
        }

        // 1. Auto-seed default cron jobs if database is empty or missing them (one lane only, avoids duplicate inserts)
        if (!$isQuietLane) {
            $this->seedDefaultJobs();
        }

        $cronJobRepository = $this->entityManager->getRepository(CronJob::class);
        $activeJobs = $cronJobRepository->findBy(['isActive' => true]);

        if (empty($activeJobs)) {
            $io->note('No active cron jobs found.');
            $writeLog('Keine aktiven Cronjobs in der Datenbank gefunden.');
            return Command::SUCCESS;
        }

        // Sort jobs to ensure optimal execution order (e.g. contracts sync before wallet & assets sync)
        $executionOrder = [
            'character:sync-online-status' => 0,
            'character:sync-contracts' => 1,
            'character:sync-wallet-assets' => 2,
            'character:sync-killmails' => 3,
            'character:sync-mining-ledger' => 4,
            'character:sync-industry-jobs' => 5,
            'structure:update-cache' => 6,
            'character:sync-pi' => 7,
            'corporation:sync-structures' => 8,
            'corporation:sync-notifications' => 9,
            'wanderer:sync-connections' => 10,
        ];
        usort($activeJobs, function(CronJob $a, CronJob $b) use ($executionOrder) {
            $orderA = $executionOrder[$a->getCommand()] ?? 99;
            $orderB = $executionOrder[$b->getCommand()] ?? 99;
            return $orderA <=> $orderB;
        });

        $dueJobsCount = 0;
        foreach ($activeJobs as $job) {
            if ($jobOption && $job->getCommand() !== $jobOption) {
                continue;
            }
            if ($jobOption === null && CronLanes::laneOf($job->getCommand()) !== $lane) {
                continue;
            }

            // Check if job is due or forced via --job
            if ($jobOption !== null || $job->getNextRunAt() === null || $now >= $job->getNextRunAt()) {
                $dueJobsCount++;
                $io->info(sprintf('Executing cron job: %s (%s)', $job->getName(), $job->getCommand()));
                $writeLog(sprintf('Führe Cronjob aus: %s (%s)', $job->getName(), $job->getCommand()));
                
                $commandName = $job->getCommand();

                // Prevent overlapping runs of the same job (cron loop and admin trigger)
                $jobLock = $this->lockFactory->createLock('cron_job_' . $commandName, CronJob::RUN_TIMEOUT_SECONDS);
                if (!$jobLock->acquire()) {
                    $io->note(sprintf('Job "%s" is still running. Skipping.', $job->getName()));
                    $writeLog(sprintf('Job "%s" läuft noch, Ausführung übersprungen.', $job->getName()), 'WARNING');
                    continue;
                }

                if (!isset($this->taskRegistry[$commandName])) {
                    $errorMsg = sprintf('Registered command service "%s" not found in container.', $commandName);
                    $io->warning($errorMsg);
                    $writeLog($errorMsg, 'ERROR');
                    
                    $job->setLastStatus('error');
                    $job->setLastError($errorMsg);
                    $job->setLastRunAt($now);
                    $this->updateNextRunAt($job, $now);
                    $this->entityManager->flush();
                    $jobLock->release();
                    continue;
                }

                // Update nextRunAt immediately to prevent concurrent runs
                $this->updateNextRunAt($job, $now);
                // Mark as running so the admin page can show progress
                $job->setLastStatus(CronJob::STATUS_RUNNING);
                $job->setLastRunAt($now);
                $this->entityManager->flush();

                $task = $this->taskRegistry[$commandName];
                
                $startTime = microtime(true);
                $this->errorCollector->start();
                try {
                    $task->execute();
                    
                    $executionTime = microtime(true) - $startTime;
                    $collectedErrors = $this->errorCollector->stop();
                    
                    $job->setLastExecutionTime($executionTime);

                    if (empty($collectedErrors)) {
                        $job->setLastStatus('success');
                        $job->setLastError(null);

                        $io->success(sprintf('Job "%s" finished successfully in %.2f seconds.', $job->getName(), $executionTime));
                        $writeLog(sprintf('Job "%s" erfolgreich beendet in %.2f Sekunden.', $job->getName(), $executionTime));
                    } else {
                        $job->setLastStatus('warning');
                        $job->setLastError($this->_formatCollectedErrors($collectedErrors));

                        $io->warning(sprintf('Job "%s" finished in %.2f seconds with %d error(s).', $job->getName(), $executionTime, count($collectedErrors)));
                        $writeLog(sprintf('Job "%s" beendet in %.2f Sekunden mit %d Fehler(n).', $job->getName(), $executionTime, count($collectedErrors)), 'WARNING');
                    }
                } catch (\Throwable $e) {
                    $executionTime = microtime(true) - $startTime;
                    $this->errorCollector->stop();
                    
                    $errorDetails = $e->getMessage() . "\n" . $e->getTraceAsString();
                    $io->error(sprintf('Job "%s" failed after %.2f seconds: %s', $job->getName(), $executionTime, $e->getMessage()));
                    $writeLog(sprintf('Job "%s" fehlgeschlagen nach %.2f Sekunden: %s', $job->getName(), $executionTime, $e->getMessage()), 'ERROR');

                    if (!$this->entityManager->isOpen()) {
                        $this->entityManager = $this->_resetEntityManager();
                        $job = $this->entityManager->find(CronJob::class, $job->getId());
                    }

                    if ($job) {
                        $job->setLastStatus('error');
                        $job->setLastError($errorDetails);
                        $job->setLastExecutionTime($executionTime);
                    }
                }

                if (!$this->entityManager->isOpen()) {
                    $this->entityManager = $this->_resetEntityManager();
                    $job = $this->entityManager->find(CronJob::class, $job->getId());
                }

                if ($job) {
                    $job->setLastRunAt($now);
                    $this->entityManager->flush();
                }

                $jobLock->release();
            }
        }

        if ($dueJobsCount === 0) {
            $writeLog('Keine Cronjobs fällig.');
        }

        $writeLog('Cronjob-Runner Ausführung beendet.');
        return Command::SUCCESS;
    }

    /**
     * @param string[] $collectedErrors
     */
    private function _formatCollectedErrors(array $collectedErrors): string
    {
        $occurrences = [];
        foreach ($collectedErrors as $message) {
            $occurrences[$message] = ($occurrences[$message] ?? 0) + 1;
        }

        $lines = [];
        foreach ($occurrences as $message => $count) {
            $lines[] = $count > 1 ? sprintf('%dx %s', $count, $message) : $message;
        }

        return implode("\n", $lines);
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
                'name' => 'Charakter-Online-Status synchronisieren (Online Check)',
                'command' => 'character:sync-online-status',
                'expression' => '*/5 * * * *', // every 5 minutes
            ],
            [
                'name' => 'Charakter-Verträge synchronisieren (Contracts)',
                'command' => 'character:sync-contracts',
                'expression' => '*/5 * * * *', // every 5 minutes
            ],
            [
                'name' => 'Charakter-Daten synchronisieren (Wallet & Inventar)',
                'command' => 'character:sync-wallet-assets',
                'expression' => '*/5 * * * *', // every 5 minutes
            ],
            [
                'name' => 'Charakter-Killmails synchronisieren (Killmails)',
                'command' => 'character:sync-killmails',
                'expression' => '*/10 * * * *', // every 10 minutes
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
                'name' => 'Struktur-Cache aktualisieren (Spieler-Strukturen)',
                'command' => 'structure:update-cache',
                'expression' => '0 */6 * * *', // every 6 hours
            ],
            [
                'name' => 'Charakter-PI-Daten synchronisieren (Planetary Industry)',
                'command' => 'character:sync-pi',
                'expression' => '0 */2 * * *', // every 2 hours
            ],
            [
                'name' => 'Corporation-Strukturen synchronisieren (Upwell & Starbases)',
                'command' => 'corporation:sync-structures',
                'expression' => '*/10 * * * *', // every 10 minutes
            ],
            [
                'name' => 'Corporation-Benachrichtigungen synchronisieren (Defense & Alerts)',
                'command' => 'corporation:sync-notifications',
                'expression' => '*/10 * * * *', // every 10 minutes
            ],
            [
                'name' => 'Wanderer-Verbindungen prüfen (Routen-Alarme)',
                'command' => 'wanderer:sync-connections',
                'expression' => '* * * * *', // every minute, fast lane
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

    private function _resetEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->doctrine->resetManager();
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('Default Doctrine manager is not an ORM EntityManager.');
        }

        return $entityManager;
    }
}
