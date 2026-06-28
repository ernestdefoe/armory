<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\Armory;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ActionController implements RequestHandlerInterface
{
    public function __construct(protected Armory $armory)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $q = $request->getQueryParams();
        $id = (int) ($q['id'] ?? 0);
        $action = (string) ($q['action'] ?? '');
        $uid = (int) $actor->id;

        $ok = match ($action) {
            'main' => $this->armory->setMain($uid, $id),
            'visible' => $this->armory->setVisible($uid, $id),
            'disconnect' => (function () use ($uid) { $this->armory->disconnect($uid); return true; })(),
            default => false,
        };

        return new JsonResponse(['ok' => $ok]);
    }
}
