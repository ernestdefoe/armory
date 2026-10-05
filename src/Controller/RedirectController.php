<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\Armory;
use ErnestDefoe\Armory\BattlenetAuth;
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
        $api = $this->armory->api();
        if (! $api->configured()) {
            return new RedirectResponse('/');
        }
        $actor = RequestUtil::getActor($request);
        // Guests start the social sign-in flow; signed-in members connect their
        // Battle.net account to the current user (the armory "link" flow).
        $mode = $actor->isGuest() ? 'login' : 'link';
        $returnTo = $this->safeReturn($request->getQueryParams()['return'] ?? '/');
        $redirectUri = (string) $request->getUri()->withQuery('')->withFragment('')->withPath('/auth/battlenet/callback');
        $state = $this->armory->signState($mode, $returnTo);
        // Bind the state to this browser, so a callback URL started by someone
        // else cannot link their Battle.net account to the member who opens it.
        $request->getAttribute('session')?->put('armory.oauth_state', $state);

        return new RedirectResponse($api->oauthHost().'/authorize?'.http_build_query([
            'client_id' => $api->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid wow.profile',
            'state' => $state,
        ]));
    }

    /** Only allow same-origin relative return paths. */
    private function safeReturn(mixed $r): string
    {
        return BattlenetAuth::safeReturn($r);
    }
}
