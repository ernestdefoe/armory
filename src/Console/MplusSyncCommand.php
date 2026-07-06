<?php

namespace ErnestDefoe\Armory\Console;

use ErnestDefoe\Armory\BlizzardApi;
use ErnestDefoe\Armory\GuildLeaderboard;
use Flarum\Console\AbstractCommand;

/**
 * `php flarum armory:mplus-sync` — rebuilds the intra-guild Mythic+ board into
 * the cache so the web endpoint only ever reads it. Runs hourly on the
 * scheduler; keeps the expensive keystone fan-out off the request path
 * (CLAUDE §50).
 */
class MplusSyncCommand extends AbstractCommand
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
            ->setName('armory:mplus-sync')
            ->setDescription('Rebuild the intra-guild Mythic+ leaderboard cache');
    }

    protected function fire(): int
    {
        if (! $this->api->configured()) {
            $this->info('Armory API is not configured — skipping M+ board sync.');

            return 0;
        }

        $rows = $this->leaderboard->buildMplusBoard();
        $this->info('M+ board rebuilt ('.count($rows).' rated characters).');

        return 0;
    }
}
