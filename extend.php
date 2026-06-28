<?php

/*
 * Armory for Flarum 2 — Battle.net sign-in + WoW character armory.
 */

use ErnestDefoe\Armory\Controller;
use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less')
        ->route('/armory', 'armory'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    new Extend\Locales(__DIR__ . '/resources/locale'),

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
        ->post('/armory/sync', 'armory.sync', Controller\SyncController::class)
        ->post('/armory/character/{id}/{action}', 'armory.action', Controller\ActionController::class),
];
