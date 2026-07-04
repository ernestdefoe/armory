<?php

namespace ErnestDefoe\Armory\Console;

use ErnestDefoe\Armory\WowCalendarSync;
use Flarum\Console\AbstractCommand;

/**
 * `php flarum armory:calendar-sync` — generates the upcoming Darkmoon Faire
 * and weekly-reset events into the calendar extension. Runs daily on the
 * scheduler; safe to run any time (idempotent, insert-only).
 */
class CalendarSyncCommand extends AbstractCommand
{
    public function __construct(protected WowCalendarSync $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('armory:calendar-sync')
            ->setDescription('Populate the calendar with upcoming WoW events (Darkmoon Faire, weekly resets)');
    }

    protected function fire(): int
    {
        if (! $this->sync->enabled()) {
            $this->info('Calendar sync is disabled in the admin settings.');

            return 0;
        }

        $n = $this->sync->sync();
        $this->info($n > 0 ? "Inserted {$n} event(s)." : 'Calendar already up to date.');

        return 0;
    }
}
