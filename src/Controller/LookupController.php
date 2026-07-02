<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\Armory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Full character sheet for a GUILD ROSTER member by realm+name (no DB row,
 * no Battle.net link needed). Membership-gated inside Armory::fullByName().
 */
class LookupController implements RequestHandlerInterface
{
    public function __construct(protected Armory $armory)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();

        return new JsonResponse($this->armory->fullByName(
            (string) ($q['realm'] ?? ''),
            rawurldecode((string) ($q['name'] ?? '')),
        ));
    }
}
