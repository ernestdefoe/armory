<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\GuildLeaderboard;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Intra-guild Mythic+ leaderboard. Reads the pre-built cache only — the
 * scheduler (`armory:mplus-sync`) does the expensive keystone fan-out, so this
 * request never blocks. Returns `pending` until the first sync populates it.
 */
class MplusLeaderboardController implements RequestHandlerInterface
{
    public function __construct(protected GuildLeaderboard $leaderboard)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $board = $this->leaderboard->mplusBoard();

        return new JsonResponse($board === null
            ? ['ok' => false, 'reason' => 'pending']
            : ['ok' => true, 'board' => $board]);
    }
}
