<?php

namespace ErnestDefoe\Armory\Console;

use ErnestDefoe\Armory\Briefing;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php flarum armory:briefing` — posts the weekly guild briefing when it's
 * due (runs hourly via the scheduler; the first tick after the regional
 * weekly reset posts it). `--force` posts immediately regardless.
 */
class BriefingCommand extends AbstractCommand
{
    public function __construct(protected Briefing $briefing)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('armory:briefing')
            ->setDescription('Post the weekly "This Week in the Pact" briefing when due')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Post now even if not due');
    }

    protected function fire(): int
    {
        $force = (bool) $this->input->getOption('force');

        if (! $force && ! $this->briefing->due()) {
            $this->info('Briefing not due.');

            return 0;
        }

        if ($force && ! $this->briefing->enabled()) {
            $this->error('Briefing is disabled in the admin settings.');

            return 1;
        }

        $id = $this->briefing->post();
        if ($id === null) {
            $this->error('Briefing failed to post — see the log.');

            return 1;
        }

        $this->info("Briefing posted (discussion #{$id}).");

        return 0;
    }
}
