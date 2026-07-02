<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\Armory;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Store;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Force a re-pull of the guild roster from Battle.net, bypassing the hourly
 * cache. Registered-users only, and throttled to one live pull every 15s
 * (shared across everyone) so a burst of clicks can't hammer the Blizzard API —
 * within the window it just returns the freshly cached roster.
 */
class GuildRefreshController implements RequestHandlerInterface
{
    public function __construct(
        protected Armory $armory,
        protected ?Store $cache = null
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertRegistered();

        $lockKey = 'armory.guild_roster_refresh_lock';
        $fresh = true;

        if ($this->cache && $this->cache->get($lockKey)) {
            $fresh = false;
        } elseif ($this->cache) {
            $this->cache->put($lockKey, 1, 15);
        }

        $data = $this->armory->guildRoster($fresh);

        if (! is_array($data)) {
            return new JsonResponse(['ok' => false, 'error' => 'unavailable']);
        }

        return new JsonResponse(['ok' => true] + $data);
    }
}
