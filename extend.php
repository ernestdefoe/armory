<?php

/*
 * Armory for Flarum 2 — Battle.net sign-in + WoW character armory.
 */

use ErnestDefoe\Armory\Controller;
use Flarum\Extend;
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
        ->serializeToForum('armory.configured', 'armory.client_id', fn ($v) => trim((string) $v) !== '')
        ->serializeToForum('armory.region', 'armory.region', fn ($v) => in_array($v, ['us', 'eu', 'kr', 'tw'], true) ? $v : 'us'),

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
        ->post('/armory/sync', 'armory.sync', Controller\SyncController::class)
        ->post('/armory/character/{id}/{action}', 'armory.action', Controller\ActionController::class),

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
