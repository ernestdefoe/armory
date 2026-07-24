<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\WeeklyVault;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/armory/vault — Great Vault progress for the signed-in member's
 * linked characters. Optional ?id={characterId} narrows it to one character.
 *
 * Registered members only: this is "your" weekly progress, and each character
 * costs two Blizzard calls on a cache miss.
 */
class VaultController implements RequestHandlerInterface
{
    public function __construct(
        protected WeeklyVault $vault
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        // Flarum 2 merges route params into the query string.
        $id = (int) ($request->getQueryParams()['id'] ?? 0);

        if ($id > 0) {
            $res = $this->vault->forCharacter($id);

            return new JsonResponse($res, ($res['ok'] ?? false) ? 200 : 404);
        }

        return new JsonResponse($this->vault->forUser((int) $actor->id));
    }
}
