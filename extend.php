<?php

/*
 * Armory for Flarum 2 — Battle.net sign-in + WoW character armory.
 */

use ErnestDefoe\Armory\ArmoryBattlenetAccount;
use ErnestDefoe\Armory\ArmoryCharacter;
use ErnestDefoe\Armory\Controller;
use ErnestDefoe\Armory\Listener\RequireBattlenetSignUp;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema\Attribute;
use Flarum\Extend;
use Flarum\User\Event\Saving;
use Flarum\User\User;
use s9e\TextFormatter\Configurator;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less')
        ->route('/armory', 'armory'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    new Extend\Locales(__DIR__ . '/resources/locale'),

    // Tell the frontend whether Battle.net sign-in is available (so the social
    // login button only shows once an admin has configured the API client).
    (new Extend\Settings())
        ->default('armory.bnet_only', false)
        ->serializeToForum('armory.configured', 'armory.client_id', fn ($v) => trim((string) $v) !== '')
        ->serializeToForum('armory.region', 'armory.region', fn ($v) => in_array($v, ['us', 'eu', 'kr', 'tw'], true) ? $v : 'us')
        ->serializeToForum('armory.bnetOnly', 'armory.bnet_only', fn ($v) => (bool) (int) $v),

    // Server-side gate for "Battle.net only" registrations (the hidden Sign Up
    // buttons are convenience, not security).
    (new Extend\Event())
        ->listen(Saving::class, RequireBattlenetSignUp::class),

    // Battle.net OAuth dance — forum (browser) routes: they carry the session,
    // so the signed-in member is resolved via RequestUtil::getActor.
    (new Extend\Routes('forum'))
        ->get('/auth/battlenet', 'armory.bnet.redirect', Controller\RedirectController::class)
        ->get('/auth/battlenet/callback', 'armory.bnet.callback', Controller\CallbackController::class),

    // JSON API.
    (new Extend\Routes('api'))
        ->get('/armory/config', 'armory.config', Controller\ConfigController::class)
        ->get('/armory/me', 'armory.me', Controller\MeController::class)
        ->get('/armory/full/{id}', 'armory.full', Controller\FullController::class)
        ->get('/armory/user/{id}', 'armory.user', Controller\UserController::class)
        ->get('/armory/extra/{id}/{kind}', 'armory.extra', Controller\ExtraController::class)
        ->get('/armory/item/{id}', 'armory.item', Controller\ItemController::class)
        ->get('/armory/item-search', 'armory.item.search', Controller\ItemSearchController::class)
        ->get('/armory/guild', 'armory.guild', Controller\GuildRosterController::class)
        ->post('/armory/sync', 'armory.sync', Controller\SyncController::class)
        ->post('/armory/character/{id}/{action}', 'armory.action', Controller\ActionController::class),

    // Per-actor nudge: true when the signed-in member has (or is about to get)
    // linked characters but hasn't explicitly confirmed a primary yet — drives
    // the "choose your primary character" onboarding alert.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Attribute::make('armoryNeedsMain')
                ->get(function ($forum, Context $context) {
                    $actor = $context->getActor();
                    if (! $actor || $actor->isGuest()) {
                        return false;
                    }
                    $acct = ArmoryBattlenetAccount::query()
                        ->where('user_id', $actor->id)
                        ->first(['id', 'main_confirmed']);
                    if ($acct) {
                        return ! $acct->main_confirmed
                            && ArmoryCharacter::query()->where('user_id', $actor->id)->exists();
                    }

                    // Fresh social signup whose link completes lazily on the
                    // first armory visit — nudge them there.
                    return $actor->loginProviders()->where('provider', 'battlenet')->exists();
                }),
        ]),

    // The author's main (visible) character on every serialized user, so the
    // post stream can show a character pane beside each post. One indexed
    // lookup per distinct author per request (memoized below); only fields the
    // public armory page already exposes.
    (new Extend\ApiResource(UserResource::class))
        ->fields(function () {
            $memo = [];

            return [
                Attribute::make('armoryMain')
                    ->get(function (User $user) use (&$memo) {
                        if (! array_key_exists($user->id, $memo)) {
                            $c = ArmoryCharacter::query()
                                ->where('user_id', $user->id)
                                ->where('is_visible', true)
                                ->orderByDesc('is_main')
                                ->orderByDesc('item_level')
                                ->first();
                            $memo[$user->id] = $c ? [
                                'name' => $c->name,
                                'realm' => $c->realm_slug,
                                'level' => $c->level,
                                'class' => $c->class,
                                'race' => $c->race,
                                'spec' => $c->spec,
                                'itemLevel' => $c->item_level,
                                'guild' => $c->guild,
                                'avatarUrl' => $c->avatar_url,
                                'renderUrl' => $c->render_url,
                            ] : null;
                        }

                        return $memo[$user->id];
                    }),
            ];
        }),

    // Parse [item=12345] in posts into a WoW item link (enhanced client-side
    // with the item name, quality color, icon, and a hover tooltip).
    (new Extend\Formatter())
        ->configure(function (Configurator $config) {
            $tagName = 'WOWITEM';
            $tag = $config->tags->add($tagName);
            $tag->attributes->add('id')->filterChain->append('#uint');
            $tag->template =
                '<a class="WowItemLink" data-wow-item="{@id}"'
                .' href="https://www.wowhead.com/item={@id}" rel="nofollow noopener" target="_blank">'
                .'<xsl:text>&#128279; item #</xsl:text><xsl:value-of select="@id"/></a>';
            $config->Preg->match('/\[item=(?<id>\d+)\]/', $tagName);
        }),
];
