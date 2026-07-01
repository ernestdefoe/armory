<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\Armory;
use Flarum\Forum\Auth\Registration;
use Flarum\Forum\Auth\ResponseFactory;
use Flarum\Http\RequestUtil;
use Flarum\User\LoginProvider;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class CallbackController implements RequestHandlerInterface
{
    public function __construct(
        protected Armory $armory,
        protected ResponseFactory $response,
        protected LoggerInterface $logger
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $code = $q['code'] ?? null;
        $state = $this->armory->readState((string) ($q['state'] ?? ''));
        if (! $code || ! $state) {
            return new RedirectResponse('/');
        }
        $redirectUri = (string) $request->getUri()->withQuery('')->withFragment('')->withPath('/auth/battlenet/callback');

        if ($state['mode'] === 'login') {
            return $this->social((string) $code, $redirectUri, $state['returnTo']);
        }

        // "link" flow — connect Battle.net to the already-signed-in member.
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            return new RedirectResponse('/');
        }
        $this->armory->completeLink((int) $actor->id, (string) $code, $redirectUri);

        return new RedirectResponse('/armory');
    }

    /**
     * Social sign-in: exchange the code, resolve the Battle.net identity, then let
     * Flarum log the member in (or begin registration) via the core ResponseFactory.
     */
    private function social(string $code, string $redirectUri, string $returnTo): ResponseInterface
    {
        $api = $this->armory->api();
        $token = $api->exchangeCode($code, $redirectUri);
        $access = $token['access_token'] ?? null;
        if (! $access) {
            return new RedirectResponse('/');
        }
        $info = $api->userInfo($access) ?? [];
        $bnetId = (string) ($info['sub'] ?? $info['id'] ?? '');
        if ($bnetId === '') {
            return new RedirectResponse('/');
        }
        $battletag = (string) ($info['battletag'] ?? '');

        // Returning members: refresh their armory link + characters automatically,
        // since this same OAuth grant already carries the wow.profile token.
        $existing = LoginProvider::query()->where('provider', 'battlenet')->where('identifier', $bnetId)->first();
        if ($existing) {
            try {
                $this->armory->storeLink((int) $existing->user_id, $token, is_array($info) ? $info : []);
            } catch (\Throwable $e) {
                $this->logger->warning('Armory: re-link on social login failed', [
                    'user_id' => (int) $existing->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->response->make(
            'battlenet',
            $bnetId,
            function (Registration $registration) use ($battletag) {
                if ($battletag !== '') {
                    $registration->suggestUsername((string) preg_replace('/#\d+$/', '', $battletag));
                }
                $registration->setPayload(['battletag' => $battletag]);
            },
            $returnTo
        );
    }
}
