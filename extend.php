<?php

/*
 * Armory for Flarum 2 — Battle.net sign-in + WoW character armory.
 */

use ErnestDefoe\Armory\Api\ForumResourceFields;
use ErnestDefoe\Armory\ArmoryCharacter;
use ErnestDefoe\Armory\Controller;
use ErnestDefoe\Armory\Listener\RequireBattlenetSignUp;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\PostResource;
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
        ->route('/armory', 'armory')
        ->route('/guild', 'armory.guildpage')
        ->route('/guild/{realm}/{name}', 'armory.guildpage.member')
        ->route('/crafting', 'armory.crafting.page'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    new Extend\Locales(__DIR__ . '/resources/locale'),

    // Weekly "This Week in the Pact" briefing: the scheduler checks hourly and
    // posts on the first tick after the regional weekly reset. The calendar
    // sync tops up upcoming Darkmoon Faire + weekly-reset events daily.
    (new Extend\Console())
        ->command(ErnestDefoe\Armory\Console\BriefingCommand::class)
        ->schedule('armory:briefing', fn (Illuminate\Console\Scheduling\Event $e) => $e->hourly())
        ->command(ErnestDefoe\Armory\Console\CalendarSyncCommand::class)
        ->schedule('armory:calendar-sync', fn (Illuminate\Console\Scheduling\Event $e) => $e->daily())
        ->command(ErnestDefoe\Armory\Console\RaidRecapCommand::class)
        ->schedule('armory:raid-recap', fn (Illuminate\Console\Scheduling\Event $e) => $e->hourly())
        ->command(ErnestDefoe\Armory\Console\GuildNewsCommand::class)
        ->schedule('armory:guild-news', fn (Illuminate\Console\Scheduling\Event $e) => $e->hourly())
        ->command(ErnestDefoe\Armory\Console\PatchNotesCommand::class)
        ->schedule('armory:patch-notes', fn (Illuminate\Console\Scheduling\Event $e) => $e->everySixHours())
        ->command(ErnestDefoe\Armory\Console\StrategyHubsCommand::class)
        ->schedule('armory:strategy-hubs', fn (Illuminate\Console\Scheduling\Event $e) => $e->daily())
        // Pre-build the M+ leaderboard + raid-progression caches off the request
        // path — the /guild endpoints only read these caches (never block on the
        // per-character Blizzard fan-out).
        ->command(ErnestDefoe\Armory\Console\MplusSyncCommand::class)
        ->schedule('armory:mplus-sync', fn (Illuminate\Console\Scheduling\Event $e) => $e->hourly())
        ->command(ErnestDefoe\Armory\Console\ProgSyncCommand::class)
        ->schedule('armory:prog-sync', fn (Illuminate\Console\Scheduling\Event $e) => $e->hourly()),

    // Tell the frontend whether Battle.net sign-in is available (so the social
    // login button only shows once an admin has configured the API client).
    (new Extend\Settings())
        ->default('armory.bnet_only', false)
        ->default('armory.briefing_enabled', false)
        ->default('armory.briefing_pin', true)
        ->default('armory.calendar_sync_enabled', false)
        ->default('armory.wcl_enabled', false)
        ->default('armory.news_enabled', false)
        ->default('armory.patchnotes_enabled', false)
        ->default('armory.strategy_enabled', false)
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
        ->get('/armory/classes', 'armory.classes', Controller\ClassesController::class)
        ->get('/armory/config', 'armory.config', Controller\ConfigController::class)
        ->get('/armory/me', 'armory.me', Controller\MeController::class)
        ->get('/armory/full/{id}', 'armory.full', Controller\FullController::class)
        ->get('/armory/user/{id}', 'armory.user', Controller\UserController::class)
        ->get('/armory/extra/{id}/{kind}', 'armory.extra', Controller\ExtraController::class)
        ->get('/armory/item/{id}', 'armory.item', Controller\ItemController::class)
        ->get('/armory/items', 'armory.items', Controller\ItemsController::class)
        ->get('/armory/item-search', 'armory.item.search', Controller\ItemSearchController::class)
        ->get('/armory/guild', 'armory.guild', Controller\GuildRosterController::class)
        ->get('/armory/guild/mplus', 'armory.guild.mplus', Controller\MplusLeaderboardController::class)
        ->get('/armory/guild/progression', 'armory.guild.progression', Controller\GuildProgressionController::class)
        ->get('/armory/crafting', 'armory.crafting', Controller\CraftingDirectoryController::class)
        ->get('/armory/crafting/search', 'armory.crafting.search', Controller\CraftingSearchController::class)
        ->get('/armory/token', 'armory.token', Controller\TokenController::class)
        ->get('/armory/vault', 'armory.vault', Controller\VaultController::class)
        ->post('/armory/guild/refresh', 'armory.guild.refresh', Controller\GuildRefreshController::class)
        ->get('/armory/lookup/{realm}/{name}', 'armory.lookup', Controller\LookupController::class)
        ->get('/armory/search', 'armory.search', Controller\SearchController::class)
        ->get('/armory/lookup-extra/{realm}/{name}/{kind}', 'armory.lookup.extra', Controller\LookupExtraController::class)
        ->post('/armory/sync', 'armory.sync', Controller\SyncController::class)
        ->post('/armory/character/{id}/{action}', 'armory.action', Controller\ActionController::class),

    // Per-actor nudge: true when the signed-in member has (or is about to get)
    // linked characters but hasn't explicitly confirmed a primary yet — drives
    // the "choose your primary character" onboarding alert.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(ForumResourceFields::class),

    // The author's main (visible) character on every serialized user, so the
    // post stream can show a character pane beside each post. One indexed
    // lookup per distinct author per request (memoized below); only fields the
    // public armory page already exposes.
    /*
     * 🚨 The badge's character is a RELATION, eager-loaded on every endpoint
     * that serialises users — exactly as core loads `user.groups`.
     *
     * The getter used to run its own query per user, so every author on a
     * discussion list, every poster on a page of posts and every row of the
     * member list cost one more query. As a relation it is one batched query
     * per page. Ordering picks the same character the old ->first() did; the
     * id is a tie-break so two equal characters always resolve the same way.
     */
    (new Extend\Model(User::class))
        ->relationship('armoryMainCharacter', fn (User $user) => $user
            ->hasOne(ArmoryCharacter::class, 'user_id')
            ->where('is_visible', true)
            ->orderByDesc('is_main')
            ->orderByDesc('item_level')
            ->orderBy('id')),

    (new Extend\ApiResource(UserResource::class))
        ->endpoint([Endpoint\Index::class, Endpoint\Show::class], fn ($endpoint) => $endpoint
            ->eagerLoad(['armoryMainCharacter'])),

    (new Extend\ApiResource(PostResource::class))
        ->endpoint([Endpoint\Index::class, Endpoint\Show::class], fn ($endpoint) => $endpoint
            ->eagerLoad(['user.armoryMainCharacter'])),

    (new Extend\ApiResource(DiscussionResource::class))
        ->endpoint(Endpoint\Index::class, fn ($endpoint) => $endpoint
            ->eagerLoad(['user.armoryMainCharacter', 'lastPostedUser.armoryMainCharacter', 'mostRelevantPost.user.armoryMainCharacter'])),

    (new Extend\ApiResource(UserResource::class))
        ->fields(function () {
            // Per-request memo: dedupes the armoryMain lookup across many posts by
            // the same author in one stream. Safe under Flarum 2's PHP-FPM model
            // (share-nothing — the container is rebuilt per request, so this
            // closure and $memo reset each request). If Flarum ever runs under a
            // persistent worker (Octane/RoadRunner), scope this to the request.
            $memo = [];

            return [
                Attribute::make('armoryMain')
                    ->get(function (User $user) use (&$memo) {
                        if (! array_key_exists($user->id, $memo)) {
                            // Eager-loaded with the page (above); a user reached
                            // any other way still lazy-loads it, once.
                            $c = $user->armoryMainCharacter;
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
