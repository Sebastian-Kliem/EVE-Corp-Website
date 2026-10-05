<?php

namespace App\Tests\Entity;

use App\Entity\CronJob;
use PHPUnit\Framework\TestCase;

class CronJobTest extends TestCase
{
    public function testFreshRunningJobIsRunning(): void
    {
        $now = new \DateTimeImmutable('2026-10-05 12:00:00');
        $job = $this->_createJob(CronJob::STATUS_RUNNING, $now->modify('-10 minutes'));

        $this->assertTrue($job->isRunning($now));
        $this->assertFalse($job->isRunAborted($now));
    }

    public function testRunningJobOlderThanLockTimeoutCountsAsAborted(): void
    {
        $now = new \DateTimeImmutable('2026-10-05 12:00:00');
        $job = $this->_createJob(CronJob::STATUS_RUNNING, $now->modify('-61 minutes'));

        $this->assertFalse($job->isRunning($now));
        $this->assertTrue($job->isRunAborted($now));
    }

    public function testFinishedJobIsNeitherRunningNorAborted(): void
    {
        $now = new \DateTimeImmutable('2026-10-05 12:00:00');
        $job = $this->_createJob('success', $now->modify('-1 minute'));

        $this->assertFalse($job->isRunning($now));
        $this->assertFalse($job->isRunAborted($now));
    }

    public function testOnlyActiveJobsWithPastNextRunAreDue(): void
    {
        $now = new \DateTimeImmutable('2026-10-05 12:00:00');
        $job = $this->_createJob('success', $now->modify('-1 hour'));

        $job->setNextRunAt($now);
        $this->assertTrue($job->isDue($now));

        $job->setNextRunAt($now->modify('+5 minutes'));
        $this->assertFalse($job->isDue($now));

        $job->setNextRunAt($now->modify('-5 minutes'));
        $job->setIsActive(false);
        $this->assertFalse($job->isDue($now));
    }

    private function _createJob(string $status, \DateTimeImmutable $lastRunAt): CronJob
    {
        $job = new CronJob();
        $job->setLastStatus($status);
        $job->setLastRunAt($lastRunAt);

        return $job;
    }
}
