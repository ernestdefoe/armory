<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\GuildLeaderboard;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/armory/guild/progression — current-expansion raid progression
 * unioned across linked characters. Reads the pre-built cache only; the
 * scheduler (`armory:prog-sync`) does the per-character raid fan-out, so this
 * request never blocks. Returns `pending` until the first sync populates it.
 */
class GuildProgressionController implements RequestHandlerInterface
{
    public function __construct(protected GuildLeaderboard $leaderboard)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $instances = $this->leaderboard->progression();

        return new JsonResponse($instances === null
            ? ['ok' => false, 'reason' => 'pending']
            : ['ok' => true, 'instances' => $instances]);
    }
}
