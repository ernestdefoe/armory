<?php

namespace ErnestDefoe\Armory\Console;

use ErnestDefoe\Armory\RaidRecap;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php flarum armory:raid-recap` — posts a recap thread for the guild's
 * most recent finished Warcraft Logs report. Runs hourly on the scheduler;
 * a processed-report ledger keeps it idempotent. `--force` reposts the
 * latest finished report even if it was already processed (testing aid).
 */
class RaidRecapCommand extends AbstractCommand
{
    public function __construct(protected RaidRecap $recap)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('armory:raid-recap')
            ->setDescription('Post a raid recap thread from the latest finished Warcraft Logs report')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Repost even if the report was already processed');
    }

    protected function fire(): int
    {
        if (! $this->recap->enabled()) {
            $this->info('Raid recaps are disabled or Warcraft Logs is not configured.');

            return 0;
        }

        $id = $this->recap->run((bool) $this->input->getOption('force'));
        $this->info($id ? "Recap posted (discussion #{$id})." : 'No new finished reports to recap.');

        return 0;
    }
}
