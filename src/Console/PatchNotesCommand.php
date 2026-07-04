<?php

namespace ErnestDefoe\Armory\Console;

use ErnestDefoe\Armory\PatchNotes;
use Flarum\Console\AbstractCommand;

/**
 * `php flarum armory:patch-notes` — posts new WoW hotfix / patch-note
 * articles. Scheduled every six hours; first run after enabling seeds
 * silently.
 */
class PatchNotesCommand extends AbstractCommand
{
    public function __construct(protected PatchNotes $notes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('armory:patch-notes')
            ->setDescription('Post new WoW hotfix / patch-note articles');
    }

    protected function fire(): int
    {
        if (! $this->notes->enabled()) {
            $this->info('Patch notes are disabled in the admin settings.');

            return 0;
        }

        $n = $this->notes->run();
        $this->info($n > 0 ? "Posted {$n} article(s)." : 'No new patch content.');

        return 0;
    }
}
