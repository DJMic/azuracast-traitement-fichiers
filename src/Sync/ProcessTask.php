<?php

declare(strict_types=1);

namespace Plugin\AzuracastTraitementFichiers\Sync;

use App\Sync\Task\AbstractTask;
use Plugin\AzuracastTraitementFichiers\Service\Processor;

/**
 * Every minute (AzuraCast synchronization, in its own process): analyses the new files whose
 * custom cue values are all empty. AzuraCast never starts this task while the previous run is
 * still going.
 */
final class ProcessTask extends AbstractTask
{
    public function __construct(
        private readonly Processor $processor
    ) {
    }

    public static function getSchedulePattern(): string
    {
        return self::SCHEDULE_EVERY_MINUTE;
    }

    public static function isLongTask(): bool
    {
        return true;
    }

    public function run(bool $force = false): void
    {
        $this->processor->run();
    }
}
