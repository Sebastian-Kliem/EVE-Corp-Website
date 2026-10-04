<?php

namespace App\Service\Cron;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Appends to var/log/cron.log, filters by level and rotates the file by size.
 */
class CronLogWriter
{
    private const LEVEL_PRIORITIES = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7,
    ];

    public const MAX_FILE_SIZE = 20 * 1024 * 1024;
    public const MAX_ARCHIVES = 3;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/log/cron.log')]
        private readonly string $logFile,
        #[Autowire('%env(CRON_LOG_LEVEL)%')]
        private readonly string $minimumLevel = 'info',
    ) {}

    public function write(string $message, string $level = 'info'): void
    {
        if (!$this->_isLevelEnabled($level)) {
            return;
        }

        $formatted = sprintf("[%s] [%s] %s\n", (new \DateTimeImmutable())->format('Y-m-d H:i:s'), strtoupper($level), $message);

        try {
            $logDir = dirname($this->logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0777, true);
            }
            $this->_rotateIfTooLarge();
            file_put_contents($this->logFile, $formatted, FILE_APPEND);
        } catch (\Throwable $e) {
            // Logging must never break a cron run or a page
        }
    }

    public function getLogFile(): string
    {
        return $this->logFile;
    }

    private function _isLevelEnabled(string $level): bool
    {
        $priority = self::LEVEL_PRIORITIES[strtolower($level)] ?? self::LEVEL_PRIORITIES['info'];
        $minimumPriority = self::LEVEL_PRIORITIES[strtolower($this->minimumLevel)] ?? self::LEVEL_PRIORITIES['info'];

        return $priority >= $minimumPriority;
    }

    // Shifts cron.log -> cron.log.1 -> ... and drops the oldest archive; concurrent writers may race harmlessly
    private function _rotateIfTooLarge(): void
    {
        clearstatcache(true, $this->logFile);
        if (!is_file($this->logFile) || filesize($this->logFile) < self::MAX_FILE_SIZE) {
            return;
        }

        @unlink($this->logFile . '.' . self::MAX_ARCHIVES);
        for ($archiveNumber = self::MAX_ARCHIVES - 1; $archiveNumber >= 1; $archiveNumber--) {
            $archiveFile = $this->logFile . '.' . $archiveNumber;
            if (is_file($archiveFile)) {
                @rename($archiveFile, $this->logFile . '.' . ($archiveNumber + 1));
            }
        }
        @rename($this->logFile, $this->logFile . '.1');
    }
}
