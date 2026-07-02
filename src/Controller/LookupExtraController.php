<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\Armory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Lazy pvp/reputations/achievements tabs for a roster lookup. */
class LookupExtraController implements RequestHandlerInterface
{
    public function __construct(protected Armory $armory)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();

        return new JsonResponse($this->armory->extraByName(
            (string) ($q['realm'] ?? ''),
            rawurldecode((string) ($q['name'] ?? '')),
            (string) ($q['kind'] ?? ''),
        ));
    }
}
