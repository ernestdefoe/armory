<?php

namespace ErnestDefoe\Armory;

use Illuminate\Contracts\Cache\Store;

/**
 * Assembles the armory page's character data — the cached full sheet
 * (equipment/stats/talents/M+/raids/professions), the lazy extra tabs
 * (pvp/reputations/achievements), the standalone item-tooltip payload, and the
 * guild-member by-name lookups (gated through GuildRoster so it never becomes an
 * open proxy for arbitrary characters).
 */
class CharacterSheet
{
    public function __construct(
        protected BlizzardApi $api,
        protected GuildRoster $guild,
        protected ?Store $cache = null,
    ) {
    }

    public function full(int $id): array
    {
        $c = ArmoryCharacter::query()->where('id', $id)->where('is_visible', true)->first();
        if (! $c) {
            return ['ok' => false];
        }
        $key = 'armory.full.'.$id;
        if ($this->cache && ($hit = $this->cache->get($key))) {
            return $hit;
        }
        $r = $c->region;
        $rs = $c->realm_slug;
        $n = $c->name;
        $data = [
            'ok' => true,
            'character' => $c->toArray(),
            'equipment' => $this->equipmentBlock($r, $rs, $n),
            'stats' => $this->statBlock($r, $rs, $n),
            'talents' => $this->talentBlock($r, $rs, $n),
            'mythic' => $this->mythicBlock($r, $rs, $n),
            'raids' => $this->raidBlock($r, $rs, $n),
            'professions' => $this->profBlock($r, $rs, $n),
        ];
        $this->cache?->put($key, $data, 600);

        return $data;
    }

    public function extra(int $id, string $kind): array
    {
        $c = ArmoryCharacter::query()->where('id', $id)->where('is_visible', true)->first();
        if (! $c || ! in_array($kind, ['pvp', 'reputations', 'achievements'], true)) {
            return ['ok' => false];
        }
        $key = 'armory.extra.'.$id.'.'.$kind;
        if ($this->cache && ($hit = $this->cache->get($key))) {
            return $hit;
        }
        $data = ['ok' => true, 'data' => match ($kind) {
            'pvp' => $this->pvpBlock($c->region, $c->realm_slug, $c->name),
            'reputations' => $this->repBlock($c->region, $c->realm_slug, $c->name),
            'achievements' => $this->achieveBlock($c->region, $c->realm_slug, $c->name),
            default => null,
        }];
        $this->cache?->put($key, $data, 600);

        return $data;
    }

    /**
     * The same full-character payload as {@see full()} but for an arbitrary
     * GUILD MEMBER by realm+name — no DB row required (guild data is public
     * via the client-credentials token). Restricted to roster members so this
     * never becomes an open proxy for arbitrary character lookups.
     */
    public function fullByName(string $realmSlug, string $name): array
    {
        $realmSlug = mb_strtolower(trim($realmSlug));
        $name = trim($name);
        if ($realmSlug === '' || $name === '' || ! $this->guild->isMember($realmSlug, $name)) {
            return ['ok' => false];
        }

        return $this->assembleByName($this->api->region(), $realmSlug, $name, 600);
    }

    /**
     * Full sheet for ANY character on ANY realm/region — the open armory lookup,
     * with no membership gate. Character data is public via the client-credentials
     * token (one token works across regions), so this is safe to expose; the
     * caller (SearchController) throttles it, and the 30-minute cache keeps
     * repeat lookups off the Blizzard rate limit.
     */
    public function publicLookup(string $region, string $realmSlug, string $name): array
    {
        $region = in_array($region, BlizzardApi::REGIONS, true) ? $region : $this->api->region();
        $realmSlug = $this->guild->slugify($realmSlug);
        $name = trim($name);
        if ($realmSlug === '' || $name === '') {
            return ['ok' => false];
        }

        return $this->assembleByName($region, $realmSlug, $name, 1800);
    }

    /**
     * Assemble the full character payload for a realm+name in a given region and
     * cache it. Shared by the gated roster lookup and the open public lookup —
     * the only difference between them is the gate and the cache lifetime.
     */
    private function assembleByName(string $r, string $realmSlug, string $name, int $ttl): array
    {
        $n = mb_strtolower($name);
        $key = 'armory.lookup.'.md5("{$r}|{$realmSlug}|{$n}");
        if ($this->cache && ($hit = $this->cache->get($key))) {
            return $hit;
        }

        $p = $this->api->character($r, $realmSlug, $n);
        if (! is_array($p)) {
            return ['ok' => false];
        }
        $media = $this->api->characterMedia($r, $realmSlug, $n) ?? [];

        $character = [
            'id' => null,
            'lookup' => true,
            'user_id' => null,
            'region' => $r,
            'realm_slug' => $realmSlug,
            'name' => (string) ($p['name'] ?? $name),
            'level' => (int) ($p['level'] ?? 0),
            'class' => $p['character_class']['name'] ?? null,
            'race' => $p['race']['name'] ?? null,
            'faction' => $p['faction']['type'] ?? null,
            'spec' => $p['active_spec']['name'] ?? null,
            'item_level' => (int) ($p['equipped_item_level'] ?? 0),
            'guild' => $p['guild']['name'] ?? null,
            'avatar_url' => $this->api->mediaUrl($media, 'avatar'),
            'render_url' => $this->api->mediaUrl($media, 'main-raw') ?? $this->api->mediaUrl($media, 'main'),
        ];

        $data = [
            'ok' => true,
            'lookup' => true,
            'character' => $character,
            'equipment' => $this->equipmentBlock($r, $realmSlug, $n),
            'stats' => $this->statBlock($r, $realmSlug, $n),
            'talents' => $this->talentBlock($r, $realmSlug, $n),
            'mythic' => $this->mythicBlock($r, $realmSlug, $n),
            'raids' => $this->raidBlock($r, $realmSlug, $n),
            'professions' => $this->profBlock($r, $realmSlug, $n),
        ];
        $this->cache?->put($key, $data, $ttl);

        return $data;
    }

    /** Lazy extra tabs (pvp/reputations/achievements) for a roster lookup. */
    public function extraByName(string $realmSlug, string $name, string $kind): array
    {
        $realmSlug = mb_strtolower(trim($realmSlug));
        $name = trim($name);
        if (! in_array($kind, ['pvp', 'reputations', 'achievements'], true)
            || $realmSlug === '' || $name === '' || ! $this->guild->isMember($realmSlug, $name)) {
            return ['ok' => false];
        }

        $r = $this->api->region();
        $n = mb_strtolower($name);
        $key = 'armory.lookupextra.'.md5("{$r}|{$realmSlug}|{$n}|{$kind}");
        if ($this->cache && ($hit = $this->cache->get($key))) {
            return $hit;
        }
        return $this->assembleExtra($r, $realmSlug, $n, $kind, 600);
    }

    /** Lazy extra tabs for the OPEN lookup — region-parameterized, no gate. */
    public function publicExtra(string $region, string $realmSlug, string $name, string $kind): array
    {
        if (! in_array($kind, ['pvp', 'reputations', 'achievements'], true)) {
            return ['ok' => false];
        }
        $region = in_array($region, BlizzardApi::REGIONS, true) ? $region : $this->api->region();
        $realmSlug = $this->guild->slugify($realmSlug);
        $name = trim($name);
        if ($realmSlug === '' || $name === '') {
            return ['ok' => false];
        }

        return $this->assembleExtra($region, $realmSlug, mb_strtolower($name), $kind, 1800);
    }

    private function assembleExtra(string $r, string $realmSlug, string $n, string $kind, int $ttl): array
    {
        $key = 'armory.lookupextra.'.md5("{$r}|{$realmSlug}|{$n}|{$kind}");
        if ($this->cache && ($hit = $this->cache->get($key))) {
            return $hit;
        }
        $data = ['ok' => true, 'data' => match ($kind) {
            'pvp' => $this->pvpBlock($r, $realmSlug, $n),
            'reputations' => $this->repBlock($r, $realmSlug, $n),
            'achievements' => $this->achieveBlock($r, $realmSlug, $n),
            default => null,
        }];
        $this->cache?->put($key, $data, $ttl);

        return $data;
    }

    /**
     * A normalized tooltip payload for a standalone item (for [item=…] post
     * links). Shaped like the equipment items so the frontend tooltip renderer
     * (buildTip) is shared. Static game data → cached a week.
     */
    public function itemCard(int $id): array
    {
        if ($id <= 0 || ! $this->api->configured()) {
            return ['ok' => false];
        }
        $region = $this->api->region();
        $key = 'armory.itemcard.'.$region.'.'.$id;
        if ($this->cache && ($hit = $this->cache->get($key))) {
            return $hit;
        }
        $data = $this->api->item($id, $region);
        if (! $data) {
            return ['ok' => false];
        }
        $p = $data['preview_item'] ?? $data;
        $card = [
            'ok' => true,
            'id' => $id,
            'name' => is_array($p['name'] ?? null) ? ($p['name']['en_US'] ?? '') : (string) ($p['name'] ?? ($data['name'] ?? 'Item #'.$id)),
            'quality' => $p['quality']['type'] ?? ($data['quality']['type'] ?? ''),
            'icon' => $this->api->itemMediaIcon($id, $region),
            'ilvlStr' => $p['level']['display_string'] ?? null,
            'nameDesc' => $p['name_description']['display_string'] ?? null,
            'binding' => $p['binding']['name'] ?? null,
            'invtype' => $p['inventory_type']['name'] ?? null,
            'type' => $p['item_subclass']['name'] ?? null,
            'armor' => $p['armor']['display']['display_string'] ?? null,
            'wep' => array_values(array_filter([
                $p['weapon']['damage']['display_string'] ?? null,
                $p['weapon']['attack_speed']['display_string'] ?? null,
                $p['weapon']['dps']['display_string'] ?? null,
            ])),
            'stats' => array_values(array_filter(array_map(fn ($s) => $s['display']['display_string'] ?? '', $p['stats'] ?? []))),
            'durability' => $p['durability']['display_string'] ?? null,
            'requires' => $p['requirements']['level']['display_string'] ?? null,
            'classes' => $p['requirements']['playable_classes']['display_string'] ?? null,
            'effects' => array_values(array_filter(array_map(fn ($sp) => $sp['description'] ?? '', $p['spells'] ?? []))),
            'sell' => $p['sell_price']['display_strings'] ?? null,
        ];
        $this->cache?->put($key, $card, 604800);

        return $card;
    }

    /** Search items by name for the composer picker. */
    public function searchItems(string $q): array
    {
        return $this->api->searchItems($q);
    }

    private function equipmentBlock(string $r, string $rs, string $n): array
    {
        $equip = $this->api->equipment($r, $rs, $n);
        $items = [];
        foreach (($equip['equipped_items'] ?? []) as $it) {
            $items[] = [
                'slot' => $it['slot']['name'] ?? ($it['slot']['type'] ?? ''),
                'name' => $it['name'] ?? '',
                'quality' => $it['quality']['type'] ?? '',
                'ilvl' => $it['level']['value'] ?? null,
                'ilvlStr' => $it['level']['display_string'] ?? null,
                'icon' => $this->api->itemIcon($it),
                'binding' => $it['binding']['name'] ?? null,
                'type' => $it['item_subclass']['name'] ?? null,
                'invtype' => $it['inventory_type']['name'] ?? null,
                'armor' => $it['armor']['display']['display_string'] ?? null,
                'stats' => array_values(array_filter(array_map(fn ($s) => $s['display']['display_string'] ?? '', $it['stats'] ?? []))),
                'durability' => $it['durability']['display_string'] ?? null,
                'requires' => $it['requirements']['level']['display_string'] ?? null,
                'classes' => $it['requirements']['playable_classes']['display_string'] ?? null,
                'sell' => $it['sell_price']['display_strings'] ?? null,
                'enchants' => array_values(array_filter(array_map(fn ($e) => $e['display_string'] ?? '', $it['enchantments'] ?? []))),
                'nameDesc' => $it['name_description']['display_string'] ?? null,
                'set' => isset($it['set']) ? [
                    'name' => $it['set']['item_set']['name'] ?? ($it['set']['display_string'] ?? ''),
                    'items' => array_map(fn ($x) => ['name' => $x['item']['name'] ?? '', 'active' => $x['is_equipped'] ?? false], $it['set']['items'] ?? []),
                    'effects' => array_map(fn ($x) => ['str' => $x['display_string'] ?? '', 'active' => $x['is_active'] ?? false], $it['set']['effects'] ?? []),
                ] : null,
            ];
        }

        return $items;
    }

    private function statBlock(string $r, string $rs, string $n): ?array
    {
        $s = $this->api->statistics($r, $rs, $n);
        if (! $s) {
            return null;
        }
        $eff = fn ($k) => is_array($s[$k] ?? null) ? ($s[$k]['effective'] ?? null) : ($s[$k] ?? null);
        $val = fn ($k) => is_array($s[$k] ?? null) ? round((float) ($s[$k]['value'] ?? 0), 2) : null;
        $armor = is_array($s['armor'] ?? null) ? ($s['armor']['effective'] ?? null) : ($s['armor'] ?? null);

        return [
            'primary' => array_values(array_filter([
                ['Strength', $eff('strength')], ['Agility', $eff('agility')],
                ['Intellect', $eff('intellect')], ['Stamina', $eff('stamina')],
            ], fn ($x) => $x[1])),
            'secondary' => [
                ['Crit', ($val('spell_crit') ?? $val('melee_crit') ?? 0).'%'],
                ['Haste', ($val('spell_haste') ?? $val('melee_haste') ?? 0).'%'],
                ['Mastery', ($val('mastery') ?? 0).'%'],
                ['Versatility', round((float) ($s['versatility_damage_done_bonus'] ?? 0), 2).'%'],
            ],
            'extra' => array_values(array_filter([
                ['Health', isset($s['health']) ? number_format((int) $s['health']) : null],
                [$s['power_type']['name'] ?? 'Power', isset($s['power']) ? number_format((int) $s['power']) : null],
                ['Armor', $armor ? number_format((int) $armor) : null],
            ], fn ($x) => $x[1])),
        ];
    }

    private function talentBlock(string $r, string $rs, string $n): ?array
    {
        $sp = $this->api->specializations($r, $rs, $n);
        if (! $sp) {
            return null;
        }
        $active = $sp['active_specialization']['name'] ?? null;
        $code = null;
        $talents = [];
        foreach ($sp['specializations'] ?? [] as $spec) {
            $isActive = ($spec['specialization']['name'] ?? null) === $active;
            foreach ($spec['loadouts'] ?? [] as $lo) {
                if (($lo['is_active'] ?? false) || ($isActive && ! $code)) {
                    $code = $lo['talent_loadout_code'] ?? $code;
                    foreach (array_merge($lo['selected_class_talents'] ?? [], $lo['selected_spec_talents'] ?? []) as $t) {
                        $nm = $t['tooltip']['talent']['name'] ?? ($t['talent']['name'] ?? null);
                        if ($nm) {
                            $talents[] = ['name' => $nm, 'rank' => $t['rank'] ?? 1];
                        }
                    }
                }
            }
        }

        return ['active' => $active, 'code' => $code, 'talents' => $talents];
    }

    private function mythicBlock(string $r, string $rs, string $n): ?array
    {
        $m = $this->api->mythicKeystone($r, $rs, $n);
        if (! $m) {
            return null;
        }
        $runs = [];
        foreach ($m['current_period']['best_runs'] ?? [] as $run) {
            $runs[] = ['dungeon' => $run['dungeon']['name'] ?? '?', 'level' => $run['keystone_level'] ?? 0, 'rating' => round((float) ($run['mythic_rating']['rating'] ?? 0), 1)];
        }
        usort($runs, fn ($a, $b) => $b['rating'] <=> $a['rating']);
        $rating = $m['current_mythic_rating']['rating'] ?? null;

        return ['rating' => $rating !== null ? round((float) $rating, 1) : null, 'runs' => array_slice($runs, 0, 12)];
    }

    private function raidBlock(string $r, string $rs, string $n): ?array
    {
        $rd = $this->api->raids($r, $rs, $n);
        $exps = $rd['expansions'] ?? [];
        if (! $exps) {
            return null;
        }
        $last = end($exps);
        $instances = [];
        foreach ($last['instances'] ?? [] as $inst) {
            $modes = [];
            foreach ($inst['modes'] ?? [] as $mode) {
                $modes[] = ['diff' => $mode['difficulty']['name'] ?? '?', 'done' => $mode['progress']['completed_count'] ?? 0, 'total' => $mode['progress']['total_count'] ?? 0];
            }
            $instances[] = ['name' => $inst['instance']['name'] ?? '?', 'modes' => $modes];
        }

        return ['expansion' => $last['expansion']['name'] ?? null, 'instances' => $instances];
    }

    private function profBlock(string $r, string $rs, string $n): ?array
    {
        $p = $this->api->professions($r, $rs, $n);
        if (! $p) {
            return null;
        }
        $map = function ($list) {
            $out = [];
            foreach ($list ?? [] as $pr) {
                $tiers = [];
                foreach ($pr['tiers'] ?? [] as $t) {
                    $tiers[] = ['name' => $t['tier']['name'] ?? '?', 'skill' => $t['skill_points'] ?? 0, 'max' => $t['max_skill_points'] ?? 0];
                }
                $out[] = ['name' => $pr['profession']['name'] ?? '?', 'tiers' => $tiers];
            }

            return $out;
        };

        return ['primary' => $map($p['primaries'] ?? []), 'secondary' => $map($p['secondaries'] ?? [])];
    }

    private function pvpBlock(string $r, string $rs, string $n): array
    {
        $sum = $this->api->pvpSummary($r, $rs, $n);
        $brackets = [];
        foreach (['2v2' => '2v2', '3v3' => '3v3', 'rbg' => 'RBG'] as $key => $label) {
            $d = $this->api->pvpBracket($r, $rs, $n, $key);
            if ($d && isset($d['rating'])) {
                $st = $d['season_match_statistics'] ?? [];
                $brackets[] = ['name' => $label, 'rating' => $d['rating'], 'won' => $st['won'] ?? null, 'lost' => $st['lost'] ?? null];
            }
        }

        return ['honor_level' => $sum['honor_level'] ?? null, 'brackets' => $brackets];
    }

    private function repBlock(string $r, string $rs, string $n): array
    {
        $d = $this->api->reputations($r, $rs, $n);
        $out = [];
        foreach (array_slice($d['reputations'] ?? [], 0, 100) as $rep) {
            $st = $rep['standing'] ?? [];
            $out[] = ['faction' => $rep['faction']['name'] ?? '?', 'standing' => $st['name'] ?? ($st['tier'] ?? ''), 'value' => $st['value'] ?? null, 'max' => $st['max'] ?? null];
        }

        return $out;
    }

    private function achieveBlock(string $r, string $rs, string $n): array
    {
        $a = $this->api->achievements($r, $rs, $n);
        $mounts = $this->api->mounts($r, $rs, $n);
        $pets = $this->api->pets($r, $rs, $n);

        return [
            'points' => $a['total_points'] ?? null,
            'count' => $a['total_quantity'] ?? null,
            'mounts' => isset($mounts['mounts']) ? count($mounts['mounts']) : null,
            'pets' => isset($pets['pets']) ? count($pets['pets']) : null,
        ];
    }
}
