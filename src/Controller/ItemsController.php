<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\Armory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Several item cards in one request: GET /api/armory/items?ids=1,2,3
 *
 * 🚨 A post that links twenty items used to fire twenty requests the moment it
 * rendered, each booting Flarum and opening its own database connection —
 * the fan-out that exhausts a shared host's connection cap and 500s the whole
 * forum. The forum now asks for a page's items in small batches.
 *
 * Capped at MAX ids: a card that is not cached yet costs two Blizzard calls,
 * so a batch stays well inside a PHP time limit. The client chunks to match.
 */
class ItemsController implements RequestHandlerInterface
{
    public const MAX = 10;

    public function __construct(protected Armory $armory)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $raw = (string) ($request->getQueryParams()['ids'] ?? '');

        $ids = array_slice(array_values(array_unique(array_filter(
            array_map('intval', explode(',', $raw)),
            fn (int $id) => $id > 0
        ))), 0, self::MAX);

        $cards = [];
        foreach ($ids as $id) {
            $cards[(string) $id] = $this->armory->itemCard($id);
        }

        return new JsonResponse(['data' => (object) $cards]);
    }
}
