<?php

namespace ErnestDefoe\Armory;

use Illuminate\Contracts\Cache\Store;

/**
 * "What do I still owe the Great Vault this week?" — per character.
 *
 * Blizzard exposes no vault endpoint, so progress is derived from the two
 * sources that do exist:
 *  • Mythic+ — `mythic-keystone-profile.current_period.best_runs`, which is
 *    scoped to the current weekly period.
 *  • Raid — `encounters/raids`, where a boss counts when its
 *    `last_kill_timestamp` falls on or after this week's reset.
 *
 * ⚠️ The Mythic+ figure is a LOWER BOUND. Blizzard returns the best run per
 * dungeon per period, so running the same dungeon repeatedly collapses to one
 * entry while the real vault counts every completion. We therefore report what
 * we can actually prove ("dungeons timed this week") and mark the slot state as
 * "at least", rather than quietly under-reporting someone's vault.
 *
 * World/delve slots are omitted entirely — there is no public endpoint for them
 * and guessing would be worse than saying nothing.
 */
class WeeklyVault
{
    /** Runs needed for each Mythic+ vault slot. */
    public const MYTHIC_THRESHOLDS = [1, 4, 8];

    /** Bosses needed for each raid vault slot. */
    public const RAID_THRESHOLDS = [2, 4, 6];

    /** Difficulty ordering, weakest first, for "highest difficulty killed". */
    private const DIFFICULTY_RANK = ['LFR' => 1, 'NORMAL' => 2, 'HEROIC' => 3, 'MYTHIC' => 4];

    public function __construct(
        protected BlizzardApi $api,
        protected WeeklyReset $reset,
        protected ?Store $cache = null
    ) {
    }

    /**
     * Vault progress for one linked character. Cached for 15 minutes — long
     * enough to spare the rate limit, short enough that finishing a key shows
     * up while you're still looking at the page.
     */
    public function forCharacter(int $characterId): array
    {
        $c = ArmoryCharacter::query()->where('id', $characterId)->where('is_visible', true)->first();
        if (! $c) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        $key = 'armory.vault.'.$c->id.'.'.$this->reset->key();
        if ($this->cache && ($hit = $this->cache->get($key))) {
            return $hit;
        }

        $since = $this->reset->last();
        $region = $c->region;
        $realm = $c->realm_slug;
        $name = strtolower($c->name);

        $out = [
            'ok' => true,
            'character' => [
                'id' => (int) $c->id,
                'name' => $c->name,
                'realm' => $realm,
                'class' => $c->class,
                'spec' => $c->spec,
                'itemLevel' => (int) $c->item_level,
            ],
            'mythic' => $this->mythicProgress($region, $realm, $name),
            'raid' => $this->raidProgress($region, $realm, $name, $since->getTimestampMs()),
            'resetAt' => $this->reset->next()->toIso8601String(),
            'secondsUntilReset' => $this->reset->secondsUntilNext(),
        ];

        if ($this->cache) {
            $this->cache->put($key, $out, 900);
        }

        return $out;
    }

    /**
     * How many characters a single vault view will fetch. Each one costs two
     * Blizzard calls on a cache miss, and this runs synchronously in the web
     * request — so it's capped to keep a large roster from timing out. Ordered
     * by main-then-item-level, the cap keeps the characters that actually matter.
     */
    public const MAX_CHARACTERS = 15;

    /** Vault progress for every visible character a member has linked. */
    public function forUser(int $userId): array
    {
        $ids = ArmoryCharacter::query()
            ->where('user_id', $userId)
            ->where('is_visible', true)
            ->orderByDesc('is_main')
            ->orderByDesc('item_level')
            ->limit(self::MAX_CHARACTERS)
            ->pluck('id');

        $characters = [];
        foreach ($ids as $id) {
            $row = $this->forCharacter((int) $id);
            if ($row['ok'] ?? false) {
                $characters[] = $row;
            }
        }

        return [
            'ok' => true,
            'characters' => $characters,
            'resetAt' => $this->reset->next()->toIso8601String(),
            'secondsUntilReset' => $this->reset->secondsUntilNext(),
        ];
    }

    /** Dungeons timed this period, with the keystone levels behind each slot. */
    private function mythicProgress(string $region, string $realm, string $name): array
    {
        $mk = $this->api->mythicKeystone($region, $realm, $name);
        $runs = $mk['current_period']['best_runs'] ?? [];
        if (! is_array($runs)) {
            $runs = [];
        }

        // Vault slots fill from the highest keystone downward.
        $levels = [];
        $detail = [];
        foreach ($runs as $run) {
            $level = (int) ($run['keystone_level'] ?? 0);
            $levels[] = $level;
            $detail[] = [
                'dungeon' => (string) ($run['dungeon']['name'] ?? '?'),
                'level' => $level,
                'timed' => (bool) ($run['is_completed_within_time'] ?? false),
            ];
        }
        rsort($levels);
        usort($detail, fn ($a, $b) => $b['level'] <=> $a['level']);

        return [
            'count' => count($runs),
            'atLeast' => true, // see the class docblock — repeats collapse
            'highest' => $levels[0] ?? 0,
            'runs' => $detail,
            'slots' => $this->slots(count($runs), self::MYTHIC_THRESHOLDS, $levels),
            'rating' => (float) ($mk['current_mythic_rating']['rating'] ?? 0),
        ];
    }

    /** Distinct bosses killed since the reset, and the best difficulty each. */
    private function raidProgress(string $region, string $realm, string $name, int $sinceMs): array
    {
        $raids = $this->api->raids($region, $realm, $name);
        $expansions = $raids['expansions'] ?? [];
        $latest = is_array($expansions) && $expansions ? $expansions[count($expansions) - 1] : null;

        $bosses = [];
        foreach ($latest['instances'] ?? [] as $instance) {
            $instanceName = (string) ($instance['instance']['name'] ?? '?');
            foreach ($instance['modes'] ?? [] as $mode) {
                $difficulty = strtoupper((string) ($mode['difficulty']['type'] ?? ''));
                foreach ($mode['progress']['encounters'] ?? [] as $encounter) {
                    if ((int) ($encounter['last_kill_timestamp'] ?? 0) < $sinceMs) {
                        continue;
                    }
                    $boss = (string) ($encounter['encounter']['name'] ?? '?');
                    $rank = self::DIFFICULTY_RANK[$difficulty] ?? 0;
                    // Same boss on several difficulties counts once, at its best.
                    if (! isset($bosses[$boss]) || $rank > $bosses[$boss]['rank']) {
                        $bosses[$boss] = [
                            'boss' => $boss,
                            'instance' => $instanceName,
                            'difficulty' => $difficulty,
                            'rank' => $rank,
                        ];
                    }
                }
            }
        }

        $list = array_values($bosses);
        usort($list, fn ($a, $b) => $b['rank'] <=> $a['rank']);
        $ranks = array_map(fn ($b) => $b['rank'], $list);

        return [
            'count' => count($list),
            'bosses' => $list,
            'instance' => $latest['instances'][0]['instance']['name'] ?? null,
            'slots' => $this->slots(count($list), self::RAID_THRESHOLDS, $ranks),
        ];
    }

    /**
     * Turn a count into vault slots. `unlockedBy` is the value that determines
     * the reward for that slot (keystone level / difficulty rank), which is the
     * Nth best result — matching how the vault picks rewards.
     *
     * @param  array<int>  $ranked  values sorted best-first
     */
    private function slots(int $count, array $thresholds, array $ranked): array
    {
        $slots = [];
        foreach ($thresholds as $threshold) {
            $filled = $count >= $threshold;
            $slots[] = [
                'need' => $threshold,
                'filled' => $filled,
                'remaining' => max(0, $threshold - $count),
                'unlockedBy' => $filled ? ($ranked[$threshold - 1] ?? null) : null,
            ];
        }

        return $slots;
    }
}
