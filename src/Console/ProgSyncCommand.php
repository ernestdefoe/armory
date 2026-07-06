<?php

namespace ErnestDefoe\Armory\Console;

use ErnestDefoe\Armory\BlizzardApi;
use ErnestDefoe\Armory\GuildLeaderboard;
use Flarum\Console\AbstractCommand;

/**
 * `php flarum armory:prog-sync` — rebuilds the guild raid-progression cache so
 * the /guild banner endpoint only reads it. Runs hourly; keeps the per-character
 * raid fan-out off the request path (CLAUDE §50).
 */
class ProgSyncCommand extends AbstractCommand
{
    public function __construct(
        protected GuildLeaderboard $leaderboard,
        protected BlizzardApi $api,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('armory:prog-sync')
            ->setDescription('Rebuild the guild raid-progression cache');
    }

    protected function fire(): int
    {
        if (! $this->api->configured()) {
            $this->info('Armory API is not configured — skipping progression sync.');

            return 0;
        }

        $out = $this->leaderboard->buildProgression();
        $this->info('Progression rebuilt ('.count($out).' raid instances).');

        return 0;
    }
}
