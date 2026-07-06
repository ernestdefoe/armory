<?php

namespace ErnestDefoe\Armory;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Store;

/**
 * The configured guild's roster + recent activity feed (client-credentials
 * token; public game data), plus the realm-slug helpers and the membership gate
 * that keeps the by-name character lookup restricted to actual guild members.
 */
class GuildRoster
{
    /** playable_class id → class name (static per game build). */
    private const GUILD_CLASSES = [
        1 => 'Warrior', 2 => 'Paladin', 3 => 'Hunter', 4 => 'Rogue',
        5 => 'Priest', 6 => 'Death Knight', 7 => 'Shaman', 8 => 'Mage',
        9 => 'Warlock', 10 => 'Monk', 11 => 'Druid', 12 => 'Demon Hunter',
        13 => 'Evoker',
    ];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected BlizzardApi $api,
        protected ?Store $cache = null,
    ) {
    }

    /** The configured guild's full roster (cached 1h), or null when unset/unavailable. */
    public function roster(bool $fresh = false): ?array
    {
        $realm = trim((string) $this->settings->get('armory.guild_realm'));
        $name = trim((string) $this->settings->get('armory.guild_name'));
        if ($realm === '' || $name === '') {
            return null;
        }

        $region = $this->api->region();
        $realmSlug = $this->slugify($realm);
        $guildSlug = $this->slugify($name);
        $key = "armory.guild_roster.{$region}.{$realmSlug}.{$guildSlug}";

        // $fresh bypasses the read (the "Refresh roster" button) but still
        // re-caches the fresh result below, so everyone benefits from the pull.
        if (! $fresh && $this->cache && ($hit = $this->cache->get($key))) {
            return $hit;
        }

        $raw = $this->api->guildRoster($region, $realmSlug, $guildSlug);

        // Connected realms: the guild entity lives under its CREATION realm's
        // slug (often not the realm members play on). On a miss, resolve the
        // canonical slug from a synced member's profile and retry once.
        if (! is_array($raw)) {
            $canonical = $this->canonicalGuildRealm($region, $guildSlug);
            if ($canonical !== null && $canonical !== $realmSlug) {
                $raw = $this->api->guildRoster($region, $canonical, $guildSlug);
            }
        }

        if (! is_array($raw) || ! is_array($raw['members'] ?? null)) {
            return null;
        }

        $members = [];
        foreach ($raw['members'] as $m) {
            $c = $m['character'] ?? null;
            if (! is_array($c) || ! isset($c['name'])) {
                continue;
            }
            $members[] = [
                'name' => (string) $c['name'],
                'realm' => (string) ($c['realm']['slug'] ?? $realmSlug),
                'level' => (int) ($c['level'] ?? 0),
                'class' => self::GUILD_CLASSES[(int) ($c['playable_class']['id'] ?? 0)] ?? null,
                'rank' => (int) ($m['rank'] ?? 99),
            ];
        }
        usort($members, fn ($a, $b) => [$a['rank'], -$a['level'], $a['name']] <=> [$b['rank'], -$b['level'], $b['name']]);

        $data = [
            'guild' => (string) ($raw['guild']['name'] ?? ''),
            'realm' => (string) ($raw['guild']['realm']['slug'] ?? $realmSlug),
            'members' => $members,
        ];
        $this->cache?->put($key, $data, 3600);

        return $data;
    }

    /**
     * Boss kills + guild achievements from the configured guild's activity
     * feed, newest first, limited to the last $days. Same canonical-realm
     * retry as the roster; cached 30 min. Null when no guild is configured or
     * the API is unreachable — the briefing degrades gracefully.
     */
    public function recentActivity(int $days = 7): ?array
    {
        $realm = trim((string) $this->settings->get('armory.guild_realm'));
        $name = trim((string) $this->settings->get('armory.guild_name'));
        if ($realm === '' || $name === '') {
            return null;
        }

        $region = $this->api->region();
        $realmSlug = $this->slugify($realm);
        $guildSlug = $this->slugify($name);
        $key = "armory.guild_activity.{$region}.{$realmSlug}.{$guildSlug}";

        if ($this->cache && ($hit = $this->cache->get($key)) !== null) {
            $raw = $hit;
        } else {
            $raw = $this->api->guildActivity($region, $realmSlug, $guildSlug);
            if (! is_array($raw)) {
                $canonical = $this->canonicalGuildRealm($region, $guildSlug);
                if ($canonical !== null && $canonical !== $realmSlug) {
                    $raw = $this->api->guildActivity($region, $canonical, $guildSlug);
                }
            }
            if (is_array($raw)) {
                $this->cache?->put($key, $raw, 1800);
            }
        }

        if (! is_array($raw)) {
            return null; // unreachable API / unknown guild
        }

        // Blizzard omits the `activities` key entirely when the feed is empty
        // (verified live) — that's a valid "no recent activity", not an error.
        $activities = is_array($raw['activities'] ?? null) ? $raw['activities'] : [];

        $cutoff = (time() - $days * 86400) * 1000; // feed timestamps are ms
        $out = [];
        foreach ($activities as $a) {
            $ts = (int) ($a['timestamp'] ?? 0);
            if ($ts < $cutoff) {
                continue;
            }
            if (isset($a['encounter_completed']['encounter']['name'])) {
                $out[] = [
                    'type' => 'kill',
                    'name' => (string) $a['encounter_completed']['encounter']['name'],
                    'mode' => (string) ($a['encounter_completed']['mode']['name'] ?? ''),
                    'timestamp' => (int) ($ts / 1000),
                ];
            } elseif (isset($a['character_achievement']['achievement']['name'])) {
                $out[] = [
                    'type' => 'achievement',
                    'name' => (string) $a['character_achievement']['achievement']['name'],
                    'who' => (string) ($a['character_achievement']['character']['name'] ?? ''),
                    'timestamp' => (int) ($ts / 1000),
                ];
            }
        }
        usort($out, fn ($x, $y) => $y['timestamp'] <=> $x['timestamp']);

        return $out;
    }

    /** True when realm+name matches a member of the configured guild's roster. */
    public function isMember(string $realmSlug, string $name): bool
    {
        $roster = $this->roster();
        if (! $roster) {
            return false;
        }
        $realmSlug = mb_strtolower($realmSlug);
        $name = mb_strtolower($name);
        foreach ($roster['members'] as $m) {
            if (mb_strtolower($m['realm']) === $realmSlug && mb_strtolower($m['name']) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the guild's canonical realm slug by asking Blizzard about a
     * synced character that belongs to the guild. Cached a day.
     */
    private function canonicalGuildRealm(string $region, string $guildSlug): ?string
    {
        $key = "armory.guild_canonical_realm.{$region}.{$guildSlug}";
        if ($this->cache && ($hit = $this->cache->get($key))) {
            return $hit === '' ? null : $hit;
        }

        $member = ArmoryCharacter::query()
            ->whereNotNull('guild')
            ->where('region', $region)
            ->get(['name', 'realm_slug', 'guild'])
            ->first(fn ($c) => $this->slugify((string) $c->guild) === $guildSlug);

        $slug = null;
        if ($member) {
            $profile = $this->api->character($region, $member->realm_slug, mb_strtolower($member->name));
            $slug = $profile['guild']['realm']['slug'] ?? null;
        }
        $this->cache?->put($key, $slug ?? '', 86400);

        return $slug;
    }

    /** Blizzard slug: lowercase, apostrophes dropped, spaces become dashes. */
    public function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(["'", "\u{2019}"], '', $value);

        return preg_replace('/\s+/', '-', $value) ?? $value;
    }
}
