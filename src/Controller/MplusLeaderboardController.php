<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\Armory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Intra-guild Mythic+ leaderboard — see Armory::mplusLeaderboard()
 * (linked visible characters, per-char rating cached 6h, board 30min,
 * weekly deltas vs the reset snapshot).
 */
class MplusLeaderboardController implements RequestHandlerInterface
{
    public function __construct(protected Armory $armory)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse(['ok' => true, 'board' => $this->armory->mplusLeaderboard()]);
    }
}
