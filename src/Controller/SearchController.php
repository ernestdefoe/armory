<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\CharacterSheet;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Store;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /armory/search?region=&realm=&name= — open character lookup for anyone
 * on any realm/region. Unlike the roster lookup this has no membership gate, so
 * it's the one endpoint that could be pointed at arbitrary characters. Two
 * guards keep it off the shared Blizzard rate limit:
 *  • a 30-minute per-character cache in CharacterSheet, so repeats are free;
 *  • a short-window request throttle here, tighter for guests than members,
 *    since a cold lookup is several live Blizzard calls.
 */
class SearchController implements RequestHandlerInterface
{
    private const WINDOW_SECONDS = 60;
    private const LIMIT_MEMBER = 30;
    private const LIMIT_GUEST = 10;

    public function __construct(
        protected CharacterSheet $sheet,
        protected ?Store $cache = null
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $isMember = $actor->exists;

        if ($over = $this->throttled($request, $actor->id, $isMember)) {
            return new JsonResponse(['ok' => false, 'reason' => 'rate_limited', 'retryAfter' => $over], 429);
        }

        $q = $request->getQueryParams();
        $region = (string) ($q['region'] ?? '');
        $realm = (string) ($q['realm'] ?? '');
        $name = rawurldecode((string) ($q['name'] ?? ''));
        $kind = (string) ($q['kind'] ?? '');

        // ?kind=pvp|reputations|achievements fetches one lazy tab; otherwise the
        // full sheet. Both share the throttle budget above (a sheet + its three
        // extra tabs is four requests against the window).
        $result = $kind !== ''
            ? $this->sheet->publicExtra($region, $realm, $name, $kind)
            : $this->sheet->publicLookup($region, $realm, $name);

        // A miss (bad name/realm) isn't an error — return 200 with ok:false so
        // the UI can say "no character found" rather than treating it as a fault.
        return new JsonResponse($result);
    }

    /**
     * Fixed-window counter in the cache. Returns 0 when allowed, else the
     * seconds until the window resets. Members are keyed by id, guests by IP.
     */
    private function throttled(ServerRequestInterface $request, ?int $actorId, bool $isMember): int
    {
        if (! $this->cache) {
            return 0;
        }

        $who = $isMember ? 'u'.$actorId : 'ip'.$this->clientIp($request);
        $key = 'armory.search.rl.'.$who;
        $count = (int) $this->cache->get($key, 0);
        $limit = $isMember ? self::LIMIT_MEMBER : self::LIMIT_GUEST;

        if ($count >= $limit) {
            return self::WINDOW_SECONDS;
        }
        $this->cache->put($key, $count + 1, self::WINDOW_SECONDS);

        return 0;
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();

        return (string) ($server['REMOTE_ADDR'] ?? 'unknown');
    }
}
