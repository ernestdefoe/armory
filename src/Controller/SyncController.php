<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\Armory;
use ErnestDefoe\Armory\Job\SyncCharactersJob;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Queue\Queue;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Kick off a character sync. The heavy work — up to ~60 sequential Blizzard
 * calls (character + media per toon, each with a 12s timeout) — runs off the
 * request cycle via SyncCharactersJob so it can never tie up a PHP-FPM worker,
 * and is throttled to one queued run per user per 60s so a burst of clicks (or
 * tabs) can't hammer the Blizzard API. A cheap synchronous pre-flight still
 * returns a re-auth signal when the Battle.net token is missing/expired.
 */
class SyncController implements RequestHandlerInterface
{
    public function __construct(
        protected Armory $armory,
        protected Queue $queue,
        protected ?Store $cache = null
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $userId = (int) $actor->id;

        // Not linked / expired token → tell the UI to re-authenticate rather
        // than queue a job that would just no-op.
        $gate = $this->armory->syncGate($userId);
        if ($gate !== null) {
            return new JsonResponse($gate);
        }

        $since = $this->armory->lastSyncedAt($userId);
        $lockKey = 'armory.sync_lock.'.$userId;

        // Throttle: one queued sync per user per 60s. Within the window we just
        // report the in-flight sync so the client keeps polling for the result.
        if ($this->cache && $this->cache->get($lockKey)) {
            return new JsonResponse(['ok' => true, 'queued' => true, 'throttled' => true, 'since' => $since]);
        }
        if ($this->cache) {
            $this->cache->put($lockKey, 1, 60);
        }

        $this->queue->push(new SyncCharactersJob($userId));

        return new JsonResponse(['ok' => true, 'queued' => true, 'since' => $since]);
    }
}
