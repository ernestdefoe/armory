<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\Armory;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CallbackController implements RequestHandlerInterface
{
    public function __construct(protected Armory $armory)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $q = $request->getQueryParams();
        $code = $q['code'] ?? null;
        $state = (string) ($q['state'] ?? '');
        if ($actor->isGuest() || ! $code || ! $this->armory->verifyState($state)) {
            return new RedirectResponse('/');
        }
        $redirectUri = (string) $request->getUri()->withQuery('')->withFragment('')->withPath('/auth/battlenet/callback');
        $this->armory->completeLink((int) $actor->id, (string) $code, $redirectUri);

        return new RedirectResponse('/armory');
    }
}
