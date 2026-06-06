<?php

namespace App\Service\Cron;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.cron_task')]
interface CronTaskInterface
{
    /**
     * Returns the command identifier stored in CronJob.command.
     * e.g. "character:sync-wallet-assets"
     */
    public function getCommandName(): string;

    /**
     * Executes the task.
     *
     * @throws \Exception on execution failure
     */
    public function execute(): void;
}
