<?php

namespace ErnestDefoe\Armory;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\ConnectionInterface;

/**
 * All Armory logic: Battle.net OAuth helpers, character sync, and the data
 * assembled for the armory page tabs. Ported from the Convoro version.
 */
class Armory
{
    protected BlizzardApi $api;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db,
        protected ?Store $cache = null
    ) {
        $this->api = new BlizzardApi($settings, $cache);
    }

    public function api(): BlizzardApi
    {
        return $this->api;
    }

    public function config(): array
    {
        return ['configured' => $this->api->configured(), 'region' => $this->api->region()];
    }

    // ── OAuth (link-only: the member must already be signed in) ─────────────

    /** A signed, stateless OAuth state token (no session dependency). */
    public function signState(): string
    {
        $payload = base64_encode(json_encode(['n' => bin2hex(random_bytes(8)), 't' => time()]));

        return $payload.'.'.hash_hmac('sha256', $payload, $this->stateSecret());
    }

    public function verifyState(string $state): bool
    {
        $parts = explode('.', $state, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$payload, $sig] = $parts;
        if (! hash_equals(hash_hmac('sha256', $payload, $this->stateSecret()), $sig)) {
            return false;
        }
        $data = json_decode((string) base64_decode($payload), true);

        return is_array($data) && isset($data['t']) && (time() - (int) $data['t']) < 600;
    }

    private function stateSecret(): string
    {
        $s = (string) $this->settings->get('armory.state_secret');
        if ($s === '') {
            $s = bin2hex(random_bytes(32));
            $this->settings->set('armory.state_secret', $s);
        }

        return $s;
    }

    /** Exchange the code, link the Battle.net account to the user, sync characters. */
    public function completeLink(int $userId, string $code, string $redirectUri): bool
    {
        $token = $this->api->exchangeCode($code, $redirectUri);
        if (! ($token['access_token'] ?? null)) {
            return false;
        }
        $info = $this->api->userInfo($token['access_token']);
        $bnetId = (string) ($info['sub'] ?? $info['id'] ?? '');
        if ($bnetId === '') {
            return false;
        }
        $owner = $this->db->table('armory_battlenet_accounts')->where('bnet_id', $bnetId)->first();
        if ($owner && (int) $owner->user_id !== $userId) {
            return false; // linked to someone else
        }
        $this->db->table('armory_battlenet_accounts')->updateOrInsert(
            ['user_id' => $userId],
            [
                'bnet_id' => $bnetId,
                'battletag' => $info['battletag'] ?? null,
                'region' => $this->api->region(),
                'access_token' => $token['access_token'],
                'token_expires_at' => isset($token['expires_in']) ? Carbon::now()->addSeconds((int) $token['expires_in']) : null,
                'updated_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]
        );
        try {
            $this->sync($userId);
        } catch (\Throwable $e) {
        }

        return true;
    }

    // ── Member view ────────────────────────────────────────────────────────

    public function me(User $user): array
    {
        $acct = $this->db->table('armory_battlenet_accounts')->where('user_id', $user->id)->first();

        return [
            'configured' => $this->api->configured(),
            'connected' => (bool) $acct,
            'battletag' => $acct->battletag ?? null,
            'region' => $acct->region ?? $this->api->region(),
            'synced_at' => $acct->synced_at ?? null,
            'characters' => $this->characters((int) $user->id),
        ];
    }

    public function characters(int $userId): array
    {
        return $this->db->table('armory_characters')->where('user_id', $userId)
            ->orderByDesc('is_main')->orderByDesc('item_level')->orderByDesc('level')
            ->get()->map(fn ($c) => (array) $c)->all();
    }

    public function visibleCharacters(int $userId): array
    {
        return $this->db->table('armory_characters')->where('user_id', $userId)->where('is_visible', true)
            ->orderByDesc('is_main')->orderByDesc('item_level')->orderByDesc('level')
            ->get()->map(fn ($c) => (array) $c)->all();
    }

    public function setMain(int $userId, int $charId): bool
    {
        if (! $this->db->table('armory_characters')->where('id', $charId)->where('user_id', $userId)->exists()) {
            return false;
        }
        $this->db->table('armory_characters')->where('user_id', $userId)->update(['is_main' => false]);
        $this->db->table('armory_characters')->where('id', $charId)->update(['is_main' => true, 'is_visible' => true]);

        return true;
    }

    public function setVisible(int $userId, int $charId): bool
    {
        $row = $this->db->table('armory_characters')->where('id', $charId)->where('user_id', $userId)->first();
        if (! $row) {
            return false;
        }
        $this->db->table('armory_characters')->where('id', $charId)->update(['is_visible' => ! $row->is_visible]);

        return true;
    }

    public function disconnect(int $userId): void
    {
        $this->db->table('armory_characters')->where('user_id', $userId)->delete();
        $this->db->table('armory_battlenet_accounts')->where('user_id', $userId)->delete();
    }

    // ── Sync ─────────────────────────────────────────────────────────────

    public function sync(int $userId): array
    {
        $acct = $this->db->table('armory_battlenet_accounts')->where('user_id', $userId)->first();
        if (! $acct || ! $acct->access_token) {
            return ['ok' => false, 'reason' => 'not_linked'];
        }
        $region = $acct->region ?: $this->api->region();
        $profile = $this->api->accountProfile($acct->access_token, $region);
        if (! $profile) {
            return ['ok' => false, 'reason' => 'profile_unavailable'];
        }

        $found = 0;
        foreach ($profile['wow_accounts'] ?? [] as $wow) {
            foreach ($wow['characters'] ?? [] as $c) {
                $realmSlug = $c['realm']['slug'] ?? null;
                $name = $c['name'] ?? null;
                if (! $realmSlug || ! $name) {
                    continue;
                }
                $found++;
                $this->db->table('armory_characters')->updateOrInsert(
                    ['region' => $region, 'realm_slug' => $realmSlug, 'name' => $name],
                    [
                        'user_id' => $userId,
                        'character_id' => $c['id'] ?? null,
                        'level' => $c['level'] ?? 0,
                        'class' => $c['playable_class']['name'] ?? null,
                        'race' => $c['playable_race']['name'] ?? null,
                        'faction' => $c['faction']['type'] ?? ($c['faction']['name'] ?? null),
                        'updated_at' => Carbon::now(),
                    ]
                );
            }
        }

        $rows = $this->db->table('armory_characters')->where('user_id', $userId)->where('region', $region)
            ->orderByDesc('level')->limit(30)->get();
        foreach ($rows as $row) {
            $detail = $this->api->character($region, $row->realm_slug, $row->name);
            $media = $this->api->characterMedia($region, $row->realm_slug, $row->name);
            $update = ['synced_at' => Carbon::now(), 'updated_at' => Carbon::now()];
            if ($detail) {
                $update['item_level'] = $detail['equipped_item_level'] ?? $detail['average_item_level'] ?? $row->item_level;
                $update['guild'] = $detail['guild']['name'] ?? $row->guild;
                $update['spec'] = $detail['active_spec']['name'] ?? $row->spec;
                $update['faction'] = $detail['faction']['type'] ?? $row->faction;
            }
            if ($media) {
                $update['avatar_url'] = $this->api->mediaUrl($media, 'avatar') ?? $row->avatar_url;
                $update['render_url'] = $this->api->mediaUrl($media, 'main-raw') ?? $this->api->mediaUrl($media, 'main') ?? $row->render_url;
            }
            $this->db->table('armory_characters')->where('id', $row->id)->update($update);
        }

        if (! $this->db->table('armory_characters')->where('user_id', $userId)->where('is_main', true)->exists()) {
            $top = $this->db->table('armory_characters')->where('user_id', $userId)->orderByDesc('item_level')->orderByDesc('level')->first();
            if ($top) {
                $this->db->table('armory_characters')->where('id', $top->id)->update(['is_main' => true]);
            }
        }
        $this->db->table('armory_battlenet_accounts')->where('user_id', $userId)->update(['synced_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        return ['ok' => true, 'found' => $found];
    }

    // ── Full character (cached) + tab blocks ───────────────────────────────

    public function full(int $id): array
    {
        $c = $this->db->table('armory_characters')->where('id', $id)->where('is_visible', true)->first();
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
            'character' => (array) $c,
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
        $c = $this->db->table('armory_characters')->where('id', $id)->where('is_visible', true)->first();
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
