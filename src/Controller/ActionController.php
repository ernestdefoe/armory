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

        // The Role-Play / Arena imports return a richer payload (cards generated, name).
        if ($action === 'roleplay') {
            return new JsonResponse($this->armory->toRoleplay($uid, $id));
        }
        if ($action === 'arena') {
            return new JsonResponse($this->armory->toArena($uid, $id));
        }

        $ok = match ($action) {
            'main' => $this->armory->setMain($uid, $id),
            'visible' => $this->armory->setVisible($uid, $id),
            'disconnect' => (function () use ($uid) { $this->armory->disconnect($uid); return true; })(),
            default => false,
        };

        return new JsonResponse(['ok' => $ok]);
    }
}
