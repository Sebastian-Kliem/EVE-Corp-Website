<?php

namespace App\Service\Cron;

use App\Entity\EveCharacter;
use App\Entity\EveCharacterIndustryJob;
use App\Service\Esi\EsiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class UpdateCharacterIndustryJobsTask implements CronTaskInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EsiClient $esiClient,
        private readonly LoggerInterface $logger
    ) {}

    public function getCommandName(): string
    {
        return 'character:sync-industry-jobs';
    }

    public function execute(): void
    {
        $characterRepository = $this->entityManager->getRepository(EveCharacter::class);
        /** @var EveCharacter[] $characters */
        $characters = $characterRepository->findAll();

        $this->logger->info(sprintf('[Cron] Starting sync-industry-jobs for %d characters.', count($characters)));

        // 1. Purge all cached industry jobs first (keeps the database clean of completed jobs)
        $this->entityManager->createQueryBuilder()
            ->delete(EveCharacterIndustryJob::class, 'j')
            ->getQuery()
            ->execute();

        // Index all local characters by ID for fast lookup during corp job syncing
        $localCharactersMap = [];
        foreach ($characters as $char) {
            $localCharactersMap[$char->getId()] = $char;
        }

        $syncedCorpIds = [];

        foreach ($characters as $character) {
            if (empty($character->getRefreshToken())) {
                continue;
            }

            // A. Sync Personal Industry Jobs
            try {
                $this->syncPersonalJobs($character);
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    '[Cron] Failed to sync personal industry jobs for character %s (%d): %s',
                    $character->getName(),
                    $character->getId(),
                    $e->getMessage()
                ));
            }

            // B. Sync Corporation Industry Jobs (if character has roles & corporationId, and corp not yet synced)
            $corpId = $character->getCorporationId();
            if ($corpId && !in_array($corpId, $syncedCorpIds, true)) {
                try {
                    $hasSynced = $this->syncCorpJobs($character, $corpId, $localCharactersMap);
                    if ($hasSynced) {
                        $syncedCorpIds[] = $corpId;
                    }
                } catch (\Exception $e) {
                    // Log but continue (character might simply not have Factory Manager roles)
                    $this->logger->debug(sprintf(
                        '[Cron] Skipping corp jobs sync for corporation %d using character %s: %s',
                        $corpId,
                        $character->getName(),
                        $e->getMessage()
                    ));
                }
            }
        }

        $this->logger->info('[Cron] Finished sync-industry-jobs execution.');
    }

    private function syncPersonalJobs(EveCharacter $character): void
    {
        $this->logger->debug(sprintf('[Cron] Syncing personal industry jobs for character %s...', $character->getName()));

        try {
            $jobsData = $this->esiClient->request(
                'GET',
                sprintf('characters/%d/industry/jobs/', $character->getId()),
                [
                    'query' => ['include_completed' => 'false']
                ],
                $character
            );
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            if ($e->getResponse()->getStatusCode() === 403) {
                $this->logger->warning(sprintf('[Cron] Character %s lacks scope or permission for personal industry jobs.', $character->getName()));
                return;
            }
            throw $e;
        }

        if (empty($jobsData) || !is_array($jobsData)) {
            $character->setLastIndustryJobsUpdate(new \DateTimeImmutable());
            $this->entityManager->flush();
            return;
        }

        $this->entityManager->wrapInTransaction(function() use ($character, $jobsData) {
            foreach ($jobsData as $jobData) {
                $this->saveJob($jobData, $character);
            }

            $character->setLastIndustryJobsUpdate(new \DateTimeImmutable());
            $this->entityManager->flush();
        });

        $this->logger->info(sprintf(
            '[Cron] Synced %d active personal industry jobs for character %s.',
            count($jobsData),
            $character->getName()
        ));
    }

    private function syncCorpJobs(EveCharacter $character, int $corpId, array $localCharactersMap): bool
    {
        $this->logger->debug(sprintf('[Cron] Trying to sync corporation industry jobs for corporation %d using character %s...', $corpId, $character->getName()));

        try {
            $jobsData = $this->esiClient->request(
                'GET',
                sprintf('corporations/%d/industry/jobs/', $corpId),
                [
                    'query' => ['include_completed' => 'false']
                ],
                $character
            );
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            // A 403 error means the character doesn't have the scope or corporation roles
            if ($e->getResponse()->getStatusCode() === 403) {
                return false;
            }
            throw $e;
        }

        if (empty($jobsData) || !is_array($jobsData)) {
            return true;
        }

        $savedCount = 0;
        $this->entityManager->wrapInTransaction(function() use ($jobsData, $localCharactersMap, &$savedCount) {
            foreach ($jobsData as $jobData) {
                $installerId = (int) $jobData['installer_id'];
                
                // Only save the corporation job if the installer character belongs to our system
                if (isset($localCharactersMap[$installerId])) {
                    $this->saveJob($jobData, $localCharactersMap[$installerId]);
                    $savedCount++;
                }
            }
        });

        $this->logger->info(sprintf(
            '[Cron] Synced %d active corporation industry jobs for corporation %d (matched %d to local characters).',
            count($jobsData),
            $corpId,
            $savedCount
        ));

        return true;
    }

    private function saveJob(array $jobData, EveCharacter $character): void
    {
        $jobId = (string) $jobData['job_id'];

        $job = new EveCharacterIndustryJob();
        $job->setJobId($jobId);
        $job->setCharacter($character);

        $job->setInstallerId((int) $jobData['installer_id']);
        $job->setBlueprintId((string) $jobData['blueprint_id']);
        $job->setBlueprintTypeId((int) $jobData['blueprint_type_id']);
        $job->setBlueprintLocationId((string) $jobData['blueprint_location_id']);
        $job->setOutputLocationId((string) $jobData['output_location_id']);
        $job->setProductTypeId(isset($jobData['product_type_id']) ? (int) $jobData['product_type_id'] : null);
        $job->setActivityId((int) $jobData['activity_id']);
        $job->setRuns((int) $jobData['runs']);
        $job->setSuccessfulRuns(isset($jobData['successful_runs']) ? (int) $jobData['successful_runs'] : null);
        $job->setDuration((int) $jobData['duration']);
        
        $job->setStartDate(new \DateTimeImmutable($jobData['start_date']));
        $job->setEndDate(new \DateTimeImmutable($jobData['end_date']));
        
        $job->setPauseDate(isset($jobData['pause_date']) ? new \DateTimeImmutable($jobData['pause_date']) : null);
        $job->setCompletedDate(isset($jobData['completed_date']) ? new \DateTimeImmutable($jobData['completed_date']) : null);
        $job->setCompletedCharacterId(isset($jobData['completed_character_id']) ? (int) $jobData['completed_character_id'] : null);
        
        $job->setStatus((string) $jobData['status']);
        $job->setCost(isset($jobData['cost']) ? (string) $jobData['cost'] : null);
        $job->setProbability(isset($jobData['probability']) ? (float) $jobData['probability'] : null);
        $job->setLicenceLimit(isset($jobData['licence_limit']) ? (int) $jobData['licence_limit'] : null);

        $this->entityManager->persist($job);
    }
}
