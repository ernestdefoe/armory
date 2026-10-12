<?php

namespace ErnestDefoe\Armory\Job;

use ErnestDefoe\Armory\Armory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Enriches a member's WoW characters from the Blizzard API off the request
 * cycle. Character sync makes up to ~60 sequential HTTP calls, which must not
 * block the OAuth callback / login redirect — so linking dispatches this job.
 */
class SyncCharactersJob implements ShouldQueue
{
    // Queueable carries $queue, which the queue reads when it pushes the job
    // (fof/horizon warned "Undefined property ...::$queue" on every push).
    use Queueable, SerializesModels;

    public function __construct(
        public int $userId
    ) {
    }

    public function handle(Armory $armory): void
    {
        $armory->sync($this->userId);
    }
}
