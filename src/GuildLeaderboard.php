<?php

namespace ErnestDefoe\Armory;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Store;

/**
 * Intra-guild Mythic+ leaderboard and raid progression.
 *
 * Both boards are EXPENSIVE to build — the M+ board issues up to 60 keystone
 * calls and progression up to 30 raid calls, each with a 12s HTTP timeout on a
 * cold per-character cache. That work belongs on the scheduler
 * (`armory:mplus-sync` / `armory:prog-sync`, hourly), never on a web request
 * (CLAUDE §50): a cold rebuild inside a PHP-FPM worker could block it for
 * minutes and exhaust the pool. So the request-facing readers ({@see
 * mplusBoard()}, {@see progression()}) only READ the pre-built cache and return
 * null when it's cold; the controller then answers `{ok:false,reason:pending}`.
 *
 * The board caches carry a 2h TTL (longer than the hourly rebuild) so a single
 * missed scheduler tick never blanks the board between rebuilds.
 */
class GuildLeaderboard
{
    private const BOARD_KEY = 'armory.mplus.board';
    private const PROG_KEY = 'armory.guild_prog';
    private const BOARD_TTL = 7200;

    public function __construct(
        protected BlizzardApi $api,
        protected SettingsRepositoryInterface $settings,
        protected ?Store $cache = null,
    ) {
    }

    /** Pre-built M+ board, or null when the scheduler hasn't populated it yet. */
    public function mplusBoard(): ?array
    {
        $cached = $this->cache?->get(self::BOARD_KEY);

        return is_array($cached) ? $cached : null;
    }

    /** Pre-built progression, or null when cold. */
    public function progression(): ?array
    {
        $cached = $this->cache?->get(self::PROG_KEY);

        return is_array($cached) ? $cached : null;
    }

    /**
     * Rebuild the M+ board (scheduler only). Per-character ratings are cached
     * 6h, so most hourly runs only call Blizzard for the handful of characters
     * whose rating cache lapsed. Whole board cached {@see BOARD_TTL}.
     */
    public function buildMplusBoard(): array
    {
        $chars = ArmoryCharacter::query()
            ->join('users', 'users.id', '=', 'armory_characters.user_id')
            ->where('armory_characters.is_visible', true)
            ->orderByDesc('armory_characters.is_main')
            ->orderByDesc('armory_characters.item_level')
            ->limit(60)
            ->get([
                'armory_characters.id', 'armory_characters.user_id', 'armory_characters.name',
                'armory_characters.realm_slug', 'armory_characters.region',
                'armory_characters.class', 'armory_characters.spec',
                'users.username as username', 'users.avatar_url as avatar_url',
            ]);

        $rows = [];
        foreach ($chars as $ch) {
            $key = 'armory.mplus.char.'.$ch->id;
            $rating = $this->cache?->get($key);
            if (! is_numeric($rating)) {
                $mk = $this->api->mythicKeystone(
                    $ch->region ?: $this->api->region(),
                    (string) $ch->realm_slug,
                    mb_strtolower((string) $ch->name)
                );
                $rating = (float) ($mk['current_mythic_rating']['rating'] ?? 0);
                $this->cache?->put($key, $rating, 6 * 3600);
            }
            $rating = round((float) $rating, 1);
            if ($rating <= 0) {
                continue;
            }

            $rows[] = [
                'charId' => (int) $ch->id,
                'name' => (string) $ch->name,
                'class' => (string) ($ch->class ?? ''),
                'spec' => (string) ($ch->spec ?? ''),
                'realm' => (string) $ch->realm_slug,
                'userId' => (int) $ch->user_id,
                'username' => (string) $ch->username,
                'avatarUrl' => $ch->avatar_url ? (string) $ch->avatar_url : null,
                'rating' => $rating,
            ];
        }

        usort($rows, fn ($a, $b) => $b['rating'] <=> $a['rating']);
        $rows = array_slice($rows, 0, 50);
        $rows = $this->applyMplusDeltas($rows);

        $this->cache?->put(self::BOARD_KEY, $rows, self::BOARD_TTL);

        return $rows;
    }

    /**
     * Rebuild guild raid progression (scheduler + the strategy-hubs cron):
     * a boss counts as killed on a difficulty when ANY linked character has
     * killed it. Carries the bosses in instance order (feeds strategy hubs).
     * Per-char raid data cached 12h; the aggregate {@see BOARD_TTL}.
     *
     * @return array<int, array{name: string, modes: array<int, array{diff: string, done: int, total: int}>, bosses: string[]}>
     */
    public function buildProgression(): array
    {
        $chars = ArmoryCharacter::query()
            ->where('is_visible', true)
            ->orderByDesc('is_main')
            ->orderByDesc('item_level')
            ->limit(30)
            ->get(['id', 'name', 'realm_slug', 'region']);

        $agg = [];
        $order = [];
        foreach ($chars as $ch) {
            foreach ($this->charRaids($ch) as $inst) {
                $name = $inst['name'];
                if (! isset($agg[$name])) {
                    $agg[$name] = ['modes' => [], 'bosses' => []];
                    $order[] = $name;
                }
                foreach ($inst['bosses'] as $boss) {
                    if (! in_array($boss, $agg[$name]['bosses'], true)) {
                        $agg[$name]['bosses'][] = $boss;
                    }
                }
                foreach ($inst['modes'] as $diff => $mode) {
                    $agg[$name]['modes'][$diff] ??= ['killed' => [], 'total' => 0];
                    $agg[$name]['modes'][$diff]['total'] = max($agg[$name]['modes'][$diff]['total'], $mode['total']);
                    $agg[$name]['modes'][$diff]['killed'] = array_unique(array_merge(
                        $agg[$name]['modes'][$diff]['killed'],
                        $mode['killed']
                    ));
                }
            }
        }

        $diffOrder = ['Raid Finder' => 0, 'Normal' => 1, 'Heroic' => 2, 'Mythic' => 3];
        $out = [];
        foreach ($order as $name) {
            $modes = [];
            foreach ($agg[$name]['modes'] as $diff => $m) {
                $modes[] = ['diff' => $diff, 'done' => count($m['killed']), 'total' => (int) $m['total']];
            }
            usort($modes, fn ($a, $b) => ($diffOrder[$a['diff']] ?? 9) <=> ($diffOrder[$b['diff']] ?? 9));
            $out[] = ['name' => $name, 'modes' => $modes, 'bosses' => $agg[$name]['bosses']];
        }

        $this->cache?->put(self::PROG_KEY, $out, self::BOARD_TTL);

        return $out;
    }

    /** One character's current-expansion raid data, normalized + cached 12h. */
    protected function charRaids(object $ch): array
    {
        $key = 'armory.raids.char.'.$ch->id;
        $cached = $this->cache?->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $rd = $this->api->raids(
            $ch->region ?: $this->api->region(),
            (string) $ch->realm_slug,
            mb_strtolower((string) $ch->name)
        );
        $exps = $rd['expansions'] ?? [];
        $last = $exps ? end($exps) : null;

        $instances = [];
        foreach ((array) ($last['instances'] ?? []) as $inst) {
            $name = (string) ($inst['instance']['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $modes = [];
            $bosses = [];
            foreach ((array) ($inst['modes'] ?? []) as $mode) {
                $diff = (string) ($mode['difficulty']['name'] ?? '?');
                $killed = [];
                foreach ((array) ($mode['progress']['encounters'] ?? []) as $enc) {
                    $encName = (string) ($enc['encounter']['name'] ?? '');
                    if ($encName === '') {
                        continue;
                    }
                    if (! in_array($encName, $bosses, true)) {
                        $bosses[] = $encName;
                    }
                    if ((int) ($enc['completed_count'] ?? 0) > 0) {
                        $killed[] = $encName;
                    }
                }
                $modes[$diff] = [
                    'total' => (int) ($mode['progress']['total_count'] ?? count($bosses)),
                    'killed' => $killed,
                ];
            }
            $instances[] = ['name' => $name, 'modes' => $modes, 'bosses' => $bosses];
        }

        $this->cache?->put($key, $instances, 12 * 3600);

        return $instances;
    }

    /** Weekly deltas vs the snapshot taken on the first build after each reset. */
    protected function applyMplusDeltas(array $rows): array
    {
        $resets = [
            'us' => [Carbon::TUESDAY, 15],
            'eu' => [Carbon::WEDNESDAY, 7],
            'kr' => [Carbon::WEDNESDAY, 7],
            'tw' => [Carbon::WEDNESDAY, 7],
        ];
        [$day, $hour] = $resets[$this->api->region()] ?? $resets['us'];
        $reset = Carbon::now('UTC')->startOfDay()->setTime($hour, 0);
        while ($reset->dayOfWeek !== $day || $reset->gt(Carbon::now('UTC'))) {
            $reset->subDay()->setTime($hour, 0);
        }
        $resetKey = $reset->format('Y-m-d');

        $snap = json_decode((string) $this->settings->get('armory.mplus_snapshot'), true);
        if (! is_array($snap) || ($snap['key'] ?? '') !== $resetKey) {
            $snap = ['key' => $resetKey, 'ratings' => []];
            foreach ($rows as $r) {
                $snap['ratings'][(string) $r['charId']] = $r['rating'];
            }
            $this->settings->set('armory.mplus_snapshot', json_encode($snap));
        }

        foreach ($rows as &$r) {
            $base = $snap['ratings'][(string) $r['charId']] ?? null;
            $r['delta'] = is_numeric($base) ? round($r['rating'] - (float) $base, 1) : 0.0;
        }

        return $rows;
    }
}
