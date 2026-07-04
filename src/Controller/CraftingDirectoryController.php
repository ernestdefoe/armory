<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\Crafting;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/armory/crafting — profession overview with crafters. */
class CraftingDirectoryController implements RequestHandlerInterface
{
    public function __construct(protected Crafting $crafting)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse(['ok' => true, 'professions' => $this->crafting->directory()]);
    }
}
