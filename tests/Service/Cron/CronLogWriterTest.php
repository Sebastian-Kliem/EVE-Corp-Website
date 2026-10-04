<?php

namespace App\Tests\Service\Cron;

use App\Service\Cron\CronLogWriter;
use PHPUnit\Framework\TestCase;

class CronLogWriterTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/cron-log-writer-test-' . uniqid() . '/cron.log';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logFile . '*') as $file) {
            unlink($file);
        }
        @rmdir(dirname($this->logFile));
    }

    public function testMessagesBelowMinimumLevelAreSkipped(): void
    {
        $writer = new CronLogWriter($this->logFile, 'info');

        $writer->write('[EsiClient] Sending actual API request', 'debug');
        $writer->write('[Scheduler] Job finished', 'INFO');
        $writer->write('[EsiClient] Request failed', 'error');

        $content = file_get_contents($this->logFile);
        $this->assertStringNotContainsString('Sending actual API request', $content);
        $this->assertStringContainsString('[INFO] [Scheduler] Job finished', $content);
        $this->assertStringContainsString('[ERROR] [EsiClient] Request failed', $content);
    }

    public function testLargeFileIsRotatedAndOldestArchiveDropped(): void
    {
        mkdir(dirname($this->logFile));
        for ($archiveNumber = 1; $archiveNumber <= CronLogWriter::MAX_ARCHIVES; $archiveNumber++) {
            file_put_contents($this->logFile . '.' . $archiveNumber, 'archive ' . $archiveNumber);
        }
        $this->_createFileOfSize($this->logFile, CronLogWriter::MAX_FILE_SIZE);

        (new CronLogWriter($this->logFile))->write('first line after rotation');

        $this->assertStringEndsWith("first line after rotation\n", file_get_contents($this->logFile));
        $this->assertSame(CronLogWriter::MAX_FILE_SIZE, filesize($this->logFile . '.1'));
        $this->assertSame('archive 1', file_get_contents($this->logFile . '.2'));
        $this->assertSame('archive 2', file_get_contents($this->logFile . '.3'));
        $this->assertFileDoesNotExist($this->logFile . '.' . (CronLogWriter::MAX_ARCHIVES + 1));
    }

    private function _createFileOfSize(string $file, int $size): void
    {
        $handle = fopen($file, 'w');
        ftruncate($handle, $size);
        fclose($handle);
    }
}
