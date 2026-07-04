<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\Crafting;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/armory/crafting/search?q= — recipe-name search across crafters. */
class CraftingSearchController implements RequestHandlerInterface
{
    public function __construct(protected Crafting $crafting)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $q = (string) Arr::get($request->getQueryParams(), 'q', '');

        return new JsonResponse(['ok' => true, 'results' => $this->crafting->search($q)]);
    }
}
