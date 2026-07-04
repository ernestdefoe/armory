<?php

namespace ErnestDefoe\Armory\Console;

use ErnestDefoe\Armory\GuildNews;
use Flarum\Console\AbstractCommand;

/**
 * `php flarum armory:guild-news` — posts first-kill news threads from the
 * Battle.net guild activity feed. Runs hourly on the scheduler; the first
 * run after enabling seeds silently instead of posting.
 */
class GuildNewsCommand extends AbstractCommand
{
    public function __construct(protected GuildNews $news)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('armory:guild-news')
            ->setDescription('Post first-kill news threads from the guild activity feed');
    }

    protected function fire(): int
    {
        if (! $this->news->enabled()) {
            $this->info('Guild news is disabled in the admin settings.');

            return 0;
        }

        $n = $this->news->run();
        $this->info($n > 0 ? "Posted {$n} news thread(s)." : 'No new first kills.');

        return 0;
    }
}
