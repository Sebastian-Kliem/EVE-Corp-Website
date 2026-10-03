<?php

namespace App\Tests\Service;

use App\Service\Cron\CronErrorCollector;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class CronErrorCollectorTest extends TestCase
{
    public function testCollectsOnlyErrorsWhileActive(): void
    {
        $collector = new CronErrorCollector();
        $logger = new Logger('test', [$collector]);

        $logger->error('before start');
        $collector->start();
        $logger->warning('missing scope');
        $logger->error('character sync failed');
        $logger->critical('esi down');
        $errors = $collector->stop();
        $logger->error('after stop');

        $this->assertSame(['character sync failed', 'esi down'], $errors);
    }

    public function testStartResetsPreviousErrors(): void
    {
        $collector = new CronErrorCollector();
        $logger = new Logger('test', [$collector]);

        $collector->start();
        $logger->error('first run');
        $collector->stop();

        $collector->start();
        $this->assertSame([], $collector->stop());
    }
}
