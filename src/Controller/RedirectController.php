<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\Armory;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RedirectController implements RequestHandlerInterface
{
    public function __construct(protected Armory $armory)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $api = $this->armory->api();
        if ($actor->isGuest() || ! $api->configured()) {
            return new RedirectResponse('/');
        }
        $redirectUri = (string) $request->getUri()->withQuery('')->withFragment('')->withPath('/auth/battlenet/callback');

        return new RedirectResponse($api->oauthHost().'/authorize?'.http_build_query([
            'client_id' => $api->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid wow.profile',
            'state' => $this->armory->signState(),
        ]));
    }
}
