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

        // Index all local characters by ID for fast lookup during corp job syncing
        $localCharactersMap = [];
        foreach ($characters as $char) {
            $localCharactersMap[$char->getId()] = $char;
        }

        $activeJobIds = [];
        $personalSyncSuccess = [];
        $corpSyncSuccess = [];

        foreach ($characters as $character) {
            if (empty($character->getRefreshToken())) {
                continue;
            }

            // A. Sync Personal Industry Jobs
            try {
                $jobs = $this->syncPersonalJobs($character);
                foreach ($jobs as $jobData) {
                    $activeJobIds[] = (string)$jobData['job_id'];
                }
                $personalSyncSuccess[$character->getId()] = true;
            } catch (\Exception $e) {
                $personalSyncSuccess[$character->getId()] = false;
                $this->logger->error(sprintf(
                    '[Cron] Failed to sync personal industry jobs for character %s (%d): %s',
                    $character->getName(),
                    $character->getId(),
                    $e->getMessage()
                ));
            }

            // B. Sync Corporation Industry Jobs (if character has roles & corporationId, and corp not yet synced)
            $corpId = $character->getCorporationId();
            if ($corpId) {
                if (!isset($corpSyncSuccess[$corpId])) {
                    $corpSyncSuccess[$corpId] = 'not_attempted';
                }
                if ($corpSyncSuccess[$corpId] !== 'success') {
                    try {
                        $result = $this->syncCorpJobs($character, $corpId, $localCharactersMap);
                        if ($result['status'] === 'success') {
                            $corpSyncSuccess[$corpId] = 'success';
                            foreach ($result['jobs'] as $jobData) {
                                $activeJobIds[] = (string)$jobData['job_id'];
                            }
                        } elseif ($result['status'] === 'no_roles') {
                            if ($corpSyncSuccess[$corpId] !== 'success') {
                                $corpSyncSuccess[$corpId] = 'no_roles';
                            }
                        }
                    } catch (\Exception $e) {
                        $corpSyncSuccess[$corpId] = 'failed';
                        $this->logger->debug(sprintf(
                            '[Cron] Skipping corp jobs sync for corporation %d using character %s: %s',
                            $corpId,
                            $character->getName(),
                            $e->getMessage()
                        ));
                    }
                }
            }
        }

        // C. Purge obsolete industry jobs for fully synced characters
        $fullySyncedCharIds = [];
        foreach ($characters as $character) {
            $charId = $character->getId();
            if (($personalSyncSuccess[$charId] ?? false) === true) {
                $corpId = $character->getCorporationId();
                if (!$corpId || in_array(($corpSyncSuccess[$corpId] ?? null), ['success', 'no_roles'], true)) {
                    $fullySyncedCharIds[] = $charId;
                }
            }
        }

        if (!empty($fullySyncedCharIds)) {
            $qb = $this->entityManager->createQueryBuilder();
            $qb->delete(EveCharacterIndustryJob::class, 'j')
                ->where('j.character IN (:charIds)');

            if (!empty($activeJobIds)) {
                $qb->andWhere('j.jobId NOT IN (:activeJobIds)')
                   ->setParameter('activeJobIds', $activeJobIds);
            }

            $qb->setParameter('charIds', $fullySyncedCharIds);
            $deletedCount = $qb->getQuery()->execute();

            $this->logger->info(sprintf('[Cron] Purged %d obsolete industry jobs for fully synced characters.', $deletedCount));
        }

        $this->logger->info('[Cron] Finished sync-industry-jobs execution.');
    }

    private function syncPersonalJobs(EveCharacter $character): array
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
                return [];
            }
            throw $e;
        }

        if (empty($jobsData) || !is_array($jobsData)) {
            $character->setLastIndustryJobsUpdate(new \DateTimeImmutable());
            $this->entityManager->flush();
            return [];
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

        return $jobsData;
    }

    private function syncCorpJobs(EveCharacter $character, int $corpId, array $localCharactersMap): array
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
                return ['status' => 'no_roles', 'jobs' => []];
            }
            throw $e;
        }

        if (empty($jobsData) || !is_array($jobsData)) {
            return ['status' => 'success', 'jobs' => []];
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

        return ['status' => 'success', 'jobs' => $jobsData];
    }

    private function saveJob(array $jobData, EveCharacter $character): void
    {
        $jobId = (string) $jobData['job_id'];

        $job = $this->entityManager->getRepository(EveCharacterIndustryJob::class)->find($jobId);
        if (!$job) {
            $job = new EveCharacterIndustryJob();
            $job->setJobId($jobId);
        }
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
