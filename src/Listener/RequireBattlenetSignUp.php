<?php

namespace ErnestDefoe\Armory\Listener;

use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\Saving;
use Flarum\User\RegistrationToken;
use Illuminate\Support\Arr;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * When "Battle.net only" mode is on, new registrations must carry a
 * battlenet-provider RegistrationToken (i.e. arrive through the Battle.net
 * OAuth flow). Admin-created accounts stay possible. This is the server-side
 * guarantee behind the hidden Sign Up buttons — hiding UI is not a gate.
 */
class RequireBattlenetSignUp
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator,
    ) {
    }

    public function handle(Saving $event): void
    {
        if ($event->user->exists) {
            return;
        }
        if (! (bool) (int) $this->settings->get('armory.bnet_only')) {
            return;
        }
        if ($event->actor->isAdmin()) {
            return;
        }

        $tokenId = Arr::get($event->data, 'attributes.token');
        $token = is_string($tokenId) && $tokenId !== '' ? RegistrationToken::query()->find($tokenId) : null;

        if (! $token || $token->provider !== 'battlenet') {
            throw new ValidationException([
                'signup' => $this->translator->trans('ernestdefoe-armory.forum.bnet_only_error'),
            ]);
        }
    }
}
