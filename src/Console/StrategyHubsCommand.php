<?php

namespace ErnestDefoe\Armory\Console;

use ErnestDefoe\Armory\StrategyHubs;
use Flarum\Console\AbstractCommand;

/**
 * `php flarum armory:strategy-hubs` — creates the missing per-boss
 * strategy threads for the guild's current raid. Scheduled daily; a
 * per-boss ledger keeps threads unique and at most two are created per
 * run.
 */
class StrategyHubsCommand extends AbstractCommand
{
    public function __construct(protected StrategyHubs $hubs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('armory:strategy-hubs')
            ->setDescription('Create per-boss strategy threads for the current raid');
    }

    protected function fire(): int
    {
        if (! $this->hubs->enabled()) {
            $this->info('Strategy hubs are disabled in the admin settings.');

            return 0;
        }

        $n = $this->hubs->run();
        $this->info($n > 0 ? "Created {$n} strategy thread(s)." : 'All current-raid bosses already have hubs.');

        return 0;
    }
}
