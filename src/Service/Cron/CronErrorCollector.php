<?php

namespace App\Service\Cron;

use App\Service\Esi\EsiMissingScopeException;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Collects error-level log records while a cron task runs, so swallowed per-character failures become visible.
 */
class CronErrorCollector extends AbstractProcessingHandler
{
    private const MAX_COLLECTED_ERRORS = 50;

    private bool $isCollecting = false;

    /** @var string[] */
    private array $errors = [];

    public function __construct()
    {
        parent::__construct(Level::Error, true);
    }

    public function start(): void
    {
        $this->errors = [];
        $this->isCollecting = true;
    }

    /**
     * @return string[] Collected error messages since start()
     */
    public function stop(): array
    {
        $this->isCollecting = false;

        return $this->errors;
    }

    protected function write(LogRecord $record): void
    {
        if (!$this->isCollecting || count($this->errors) >= self::MAX_COLLECTED_ERRORS) {
            return;
        }

        // Missing scopes are a known per-character limitation, not a task failure
        if (str_contains($record->message, EsiMissingScopeException::MESSAGE_MARKER)) {
            return;
        }

        $this->errors[] = $record->message;
    }
}
