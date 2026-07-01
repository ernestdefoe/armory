<?php

namespace ErnestDefoe\Armory;

use Carbon\Carbon;
use ErnestDefoe\Armory\Job\SyncCharactersJob;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * All Armory logic: Battle.net OAuth helpers, character sync, and the data
 * assembled for the armory page tabs. Ported from the Convoro version.
 */
class Armory
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db,
        protected BlizzardApi $api,
        protected LoggerInterface $logger,
        protected Paths $paths,
        protected Queue $queue,
        protected ?Store $cache = null
    ) {
    }

    public function api(): BlizzardApi
    {
        return $this->api;
    }

    // ── Token encryption at rest (libsodium; key lives in a file under storage/,
    //    outside the DB, so a DB dump alone can't decrypt linked users' tokens) ──

    private function tokenKey(): ?string
    {
        $file = rtrim($this->paths->storage, '/\\').'/armory-token.key';
        if (! is_file($file)) {
            try {
                file_put_contents($file, sodium_crypto_secretbox_keygen(), LOCK_EX);
                @chmod($file, 0600);
            } catch (\Throwable $e) {
                $this->logger->warning('Armory: could not create token key file', ['error' => $e->getMessage()]);

                return null;
            }
        }
        $key = @file_get_contents($file);

        return (is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) ? $key : null;
    }

    private function encryptToken(string $plain): string
    {
        $key = $this->tokenKey();
        if ($key === null) {
            return $plain; // never lose the token; fall back to storing as-is
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'sb1:'.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $key));
    }

    private function decryptToken(?string $stored): ?string
    {
        if (! $stored) {
            return null;
        }
        if (! str_starts_with($stored, 'sb1:')) {
            return $stored; // legacy plaintext token (pre-encryption)
        }
        $key = $this->tokenKey();
        $raw = base64_decode(substr($stored, 4), true);
        if ($key === null || $raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);

        return $plain === false ? null : $plain;
    }

    public function config(): array
    {
        return ['configured' => $this->api->configured(), 'region' => $this->api->region()];
    }

    // ── OAuth (two modes: 'login' = social sign-in, 'link' = connect armory) ──

    /**
     * A signed, stateless OAuth state token (no session dependency). Carries the
     * flow mode ('login' for social sign-in, 'link' for connecting Battle.net to
     * an already-signed-in member) and a same-origin return path.
     */
    public function signState(string $mode = 'link', string $returnTo = '/'): string
    {
        $payload = base64_encode(json_encode([
            'n' => bin2hex(random_bytes(8)),
            't' => time(),
            'm' => $mode === 'login' ? 'login' : 'link',
            'r' => $returnTo,
        ]));

        return $payload.'.'.hash_hmac('sha256', $payload, $this->stateSecret());
    }

    /** Verify + decode a state token. Returns ['mode', 'returnTo'] or null if invalid/expired. */
    public function readState(string $state): ?array
    {
        $parts = explode('.', $state, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $sig] = $parts;
        if (! hash_equals(hash_hmac('sha256', $payload, $this->stateSecret()), $sig)) {
            return null;
        }
        $data = json_decode((string) base64_decode($payload), true);
        if (! is_array($data) || ! isset($data['t']) || (time() - (int) $data['t']) >= 600) {
            return null;
        }

        return [
            'mode' => ($data['m'] ?? 'link') === 'login' ? 'login' : 'link',
            'returnTo' => is_string($data['r'] ?? null) ? $data['r'] : '/',
        ];
    }

    public function verifyState(string $state): bool
    {
        return $this->readState($state) !== null;
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

        return $this->storeLink($userId, $token, is_array($info) ? $info : []);
    }

    /**
     * Persist an already-exchanged Battle.net token + userinfo against a forum
     * user and sync their characters. Shared by the "connect" flow and by social
     * sign-in (which has already exchanged the code to authenticate the member).
     */
    public function storeLink(int $userId, array $token, array $info): bool
    {
        if (! ($token['access_token'] ?? null)) {
            return false;
        }
        $bnetId = (string) ($info['sub'] ?? $info['id'] ?? '');
        if ($bnetId === '') {
            return false;
        }
        $owner = ArmoryBattlenetAccount::query()->where('bnet_id', $bnetId)->first();
        if ($owner && (int) $owner->user_id !== $userId) {
            return false; // linked to someone else
        }
        ArmoryBattlenetAccount::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'bnet_id' => $bnetId,
                'battletag' => $info['battletag'] ?? null,
                'region' => $this->api->region(),
                'access_token' => $this->encryptToken((string) $token['access_token']),
                'token_expires_at' => isset($token['expires_in']) ? Carbon::now()->addSeconds((int) $token['expires_in']) : null,
            ]
        );
        // Character enrichment makes ~60 sequential Blizzard calls — run it off the
        // request cycle so it never blocks the OAuth callback / login redirect.
        $this->queue->push(new SyncCharactersJob($userId));

        return true;
    }

    // ── Member view ────────────────────────────────────────────────────────

    public function me(User $user): array
    {
        $acct = ArmoryBattlenetAccount::query()->where('user_id', $user->id)->first();

        return [
            'configured' => $this->api->configured(),
            'connected' => (bool) $acct,
            'battletag' => $acct->battletag ?? null,
            'region' => $acct->region ?? $this->api->region(),
            'synced_at' => optional($acct)->synced_at?->toIso8601String(),
            'rp_installed' => $this->rpInstalled(),
            'arena_installed' => $this->arenaInstalled(),
            'characters' => $this->characters((int) $user->id),
        ];
    }

    public function characters(int $userId): array
    {
        return $this->charactersQuery($userId)->get()->map->toArray()->all();
    }

    public function visibleCharacters(int $userId): array
    {
        return $this->charactersQuery($userId)->where('is_visible', true)->get()->map->toArray()->all();
    }

    private function charactersQuery(int $userId)
    {
        return ArmoryCharacter::query()->where('user_id', $userId)
            ->orderByDesc('is_main')->orderByDesc('item_level')->orderByDesc('level');
    }

    public function setMain(int $userId, int $charId): bool
    {
        if (! ArmoryCharacter::query()->where('id', $charId)->where('user_id', $userId)->exists()) {
            return false;
        }
        ArmoryCharacter::query()->where('user_id', $userId)->update(['is_main' => false]);
        ArmoryCharacter::query()->where('id', $charId)->update(['is_main' => true, 'is_visible' => true]);

        return true;
    }

    public function setVisible(int $userId, int $charId): bool
    {
        $character = ArmoryCharacter::query()->where('id', $charId)->where('user_id', $userId)->first();
        if (! $character) {
            return false;
        }
        $character->is_visible = ! $character->is_visible;
        $character->save();

        return true;
    }

    public function disconnect(int $userId): void
    {
        ArmoryCharacter::query()->where('user_id', $userId)->delete();
        ArmoryBattlenetAccount::query()->where('user_id', $userId)->delete();
    }

    // ── Sync ─────────────────────────────────────────────────────────────

    public function sync(int $userId): array
    {
        $acct = ArmoryBattlenetAccount::query()->where('user_id', $userId)->first();
        $accessToken = $this->decryptToken($acct->access_token ?? null);
        if (! $acct || ! $accessToken) {
            return ['ok' => false, 'reason' => 'not_linked'];
        }
        $region = $acct->region ?: $this->api->region();
        $profile = $this->api->accountProfile($accessToken, $region);
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
                ArmoryCharacter::query()->updateOrCreate(
                    ['region' => $region, 'realm_slug' => $realmSlug, 'name' => $name],
                    [
                        'user_id' => $userId,
                        'character_id' => $c['id'] ?? null,
                        'level' => $c['level'] ?? 0,
                        'class' => $c['playable_class']['name'] ?? null,
                        'race' => $c['playable_race']['name'] ?? null,
                        'faction' => $c['faction']['type'] ?? ($c['faction']['name'] ?? null),
                    ]
                );
            }
        }

        $rows = ArmoryCharacter::query()->where('user_id', $userId)->where('region', $region)
            ->orderByDesc('level')->limit(30)->get();
        foreach ($rows as $row) {
            $detail = $this->api->character($region, $row->realm_slug, $row->name);
            $media = $this->api->characterMedia($region, $row->realm_slug, $row->name);
            $update = ['synced_at' => Carbon::now()];
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
            $row->update($update);
        }

        if (! ArmoryCharacter::query()->where('user_id', $userId)->where('is_main', true)->exists()) {
            $top = ArmoryCharacter::query()->where('user_id', $userId)->orderByDesc('item_level')->orderByDesc('level')->first();
            if ($top) {
                $top->update(['is_main' => true]);
            }
        }
        $acct->update(['synced_at' => Carbon::now()]);

        return ['ok' => true, 'found' => $found];
    }

    // ── Full character (cached) + tab blocks ───────────────────────────────

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

    // ── Role-Play integration (ernestdefoe/roleplay) ───────────────────────

    public function rpInstalled(): bool
    {
        $s = $this->db->getSchemaBuilder();

        return $s->hasTable('rp_cards') && $s->hasTable('rp_sheets') && $s->hasTable('rp_characters');
    }

    /**
     * Import a WoW character into Role-Play: an rp_character + a combat sheet
     * (HP + attributes scaled from item level / primary stats) + a deck of
     * signature class ability cards (damage dice scaled by item level). Safe to
     * re-run after a gear upgrade — the generated deck is rebuilt cleanly.
     */
    public function toRoleplay(int $userId, int $id): array
    {
        if (! $this->rpInstalled()) {
            return ['ok' => false, 'error' => 'Role-Play is not installed on this forum.'];
        }
        $c = ArmoryCharacter::query()->where('id', $id)->where('user_id', $userId)->first();
        if (! $c) {
            return ['ok' => false, 'error' => 'Character not found.'];
        }

        $stats = $this->full($id)['stats'] ?? [];
        $prim = [];
        foreach ($stats['primary'] ?? [] as $p) {
            $prim[$p[0]] = (int) $p[1];
        }
        $ilvl = (int) ($c->item_level ?: 0);
        $sta = $prim['Stamina'] ?? 0;
        $clamp = fn ($v, $lo, $hi) => (int) max($lo, min($hi, round($v)));

        $slug = Str::slug($c->name.'-'.$c->realm_slug);
        $charId = $this->db->table('rp_characters')->where('user_id', $userId)->where('slug', $slug)->value('id');
        if (! $charId) {
            $charId = $this->db->table('rp_characters')->insertGetId([
                'user_id' => $userId,
                'name' => $c->name,
                'slug' => $slug,
                'avatar_url' => $c->avatar_url,
                'color' => $this->classColor((string) $c->class),
                'bio' => trim('Level '.($c->level ?: '').' '.($c->race ?: '').' '.($c->class ?: '').($c->guild ? " · <{$c->guild}>" : '')),
                'status' => 'approved',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        $maxHp = $clamp(($ilvl / 5) + ($sta / 4000), 25, 99);
        $attrs = [
            'might' => $clamp(($prim['Strength'] ?? 0) / 120, 1, 25),
            'agility' => $clamp(($prim['Agility'] ?? 0) / 120, 1, 25),
            'wits' => $clamp(($prim['Intellect'] ?? 0) / 120, 1, 25),
            'heart' => $clamp($sta / 1200, 1, 25),
        ];
        $this->db->table('rp_sheets')->updateOrInsert(
            ['character_id' => $charId],
            ['max_hp' => $maxHp, 'hp' => $maxHp, 'attributes' => json_encode($attrs), 'updated_at' => Carbon::now(), 'created_at' => Carbon::now()]
        );

        $cards = $this->classCards((string) $c->class);
        $this->db->table('rp_cards')->where('user_id', $userId)
            ->whereIn('name', array_map(fn ($x) => $x['name'], $cards))->delete();

        $mod = (int) floor($ilvl / 40);
        $equipped = [];
        foreach ($cards as $card) {
            $dmg = ! empty($card['damage']) ? $card['damage'].($mod > 0 ? '+'.$mod : '') : null;
            $equipped[] = $this->db->table('rp_cards')->insertGetId([
                'user_id' => $userId,
                'name' => $card['name'],
                'icon' => $card['icon'],
                'type' => $card['type'],
                'description' => $card['desc'] ?? '',
                'attack_expr' => $card['attack'] ?? null,
                'damage_expr' => $dmg,
                'defense' => $card['defense'] ?? 0,
                'hp' => $card['hp'] ?? 0,
                'cost' => $card['cost'] ?? 1,
                'is_public' => false,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
        $this->db->table('rp_sheets')->where('character_id', $charId)->update(['equipped' => json_encode(array_slice($equipped, 0, 6))]);

        return ['ok' => true, 'cards' => count($equipped), 'character' => $c->name];
    }

    private function classColor(string $class): string
    {
        return [
            'Death Knight' => '#C41E3A', 'Demon Hunter' => '#A330C9', 'Druid' => '#FF7C0A', 'Evoker' => '#33937F',
            'Hunter' => '#AAD372', 'Mage' => '#3FC7EB', 'Monk' => '#00FF98', 'Paladin' => '#F48CBA', 'Priest' => '#BFBFBF',
            'Rogue' => '#FFF468', 'Shaman' => '#0070DD', 'Warlock' => '#8788EE', 'Warrior' => '#C69B6D',
        ][$class] ?? '#3FC7EB';
    }

    /** Signature ability cards per WoW class (dice scaled by item level on import). */
    private function classCards(string $class): array
    {
        $c = fn ($name, $icon, $type, $attack, $damage, $defense = 0, $hp = 0, $cost = 1) => compact('name', 'icon', 'type', 'attack', 'damage', 'defense', 'hp', 'cost');

        $sets = [
            'Warlock' => [$c('Shadow Bolt', 'fas fa-skull', 'spell', '1d20+5', '2d8'), $c('Chaos Bolt', 'fas fa-fire', 'spell', '1d20+6', '3d10', 0, 0, 2), $c('Drain Life', 'fas fa-droplet', 'spell', '1d20+4', '1d8', 0, 4), $c('Fear', 'fas fa-ghost', 'spell', null, null, 3)],
            'Mage' => [$c('Frostbolt', 'fas fa-snowflake', 'spell', '1d20+5', '2d8'), $c('Fireball', 'fas fa-fire', 'spell', '1d20+5', '2d10'), $c('Arcane Blast', 'fas fa-wand-magic-sparkles', 'spell', '1d20+6', '3d8', 0, 0, 2), $c('Ice Block', 'fas fa-cube', 'spell', null, null, 8, 0, 2)],
            'Warrior' => [$c('Mortal Strike', 'fas fa-khanda', 'ability', '1d20+6', '2d10'), $c('Execute', 'fas fa-axe-battle', 'ability', '1d20+7', '3d8', 0, 0, 2), $c('Charge', 'fas fa-bolt', 'ability', '1d20+4', '1d8'), $c('Shield Wall', 'fas fa-shield', 'ability', null, null, 9, 0, 2)],
            'Hunter' => [$c('Aimed Shot', 'fas fa-crosshairs', 'ability', '1d20+6', '2d10'), $c('Kill Shot', 'fas fa-bullseye', 'ability', '1d20+7', '3d8', 0, 0, 2), $c('Multi-Shot', 'fas fa-arrows-split-up-and-left', 'ability', '1d20+4', '2d6'), $c('Feign Death', 'fas fa-heart-crack', 'ability', null, null, 5)],
            'Rogue' => [$c('Sinister Strike', 'fas fa-dagger', 'ability', '1d20+6', '2d8'), $c('Eviscerate', 'fas fa-burst', 'ability', '1d20+6', '3d8', 0, 0, 2), $c('Ambush', 'fas fa-user-ninja', 'ability', '1d20+7', '2d10'), $c('Evasion', 'fas fa-person-running', 'ability', null, null, 6)],
            'Priest' => [$c('Smite', 'fas fa-sun', 'spell', '1d20+5', '2d8'), $c('Shadow Word: Pain', 'fas fa-skull', 'spell', '1d20+4', '2d6'), $c('Heal', 'fas fa-plus', 'spell', null, null, 0, 12, 2), $c('Power Word: Shield', 'fas fa-shield-halved', 'spell', null, null, 8)],
            'Paladin' => [$c('Crusader Strike', 'fas fa-hammer', 'ability', '1d20+6', '2d8'), $c("Templar's Verdict", 'fas fa-gavel', 'ability', '1d20+6', '3d8', 0, 0, 2), $c('Holy Light', 'fas fa-plus', 'spell', null, null, 0, 12, 2), $c('Divine Shield', 'fas fa-shield', 'spell', null, null, 10, 0, 3)],
            'Druid' => [$c('Wrath', 'fas fa-leaf', 'spell', '1d20+5', '2d8'), $c('Starsurge', 'fas fa-star', 'spell', '1d20+6', '3d8', 0, 0, 2), $c('Rejuvenation', 'fas fa-seedling', 'spell', null, null, 0, 10), $c('Barkskin', 'fas fa-tree', 'spell', null, null, 6)],
            'Shaman' => [$c('Lightning Bolt', 'fas fa-bolt', 'spell', '1d20+5', '2d8'), $c('Lava Burst', 'fas fa-volcano', 'spell', '1d20+6', '3d8', 0, 0, 2), $c('Chain Lightning', 'fas fa-bolt-lightning', 'spell', '1d20+4', '2d6'), $c('Healing Surge', 'fas fa-droplet', 'spell', null, null, 0, 12, 2)],
            'Monk' => [$c('Tiger Palm', 'fas fa-hand-fist', 'ability', '1d20+6', '2d8'), $c('Rising Sun Kick', 'fas fa-sun', 'ability', '1d20+6', '3d8', 0, 0, 2), $c('Blackout Kick', 'fas fa-shoe-prints', 'ability', '1d20+5', '2d6'), $c('Fortifying Brew', 'fas fa-mug-hot', 'ability', null, null, 8)],
            'Death Knight' => [$c('Death Strike', 'fas fa-skull-crossbones', 'ability', '1d20+6', '2d10', 0, 4), $c('Obliterate', 'fas fa-snowflake', 'ability', '1d20+6', '3d8', 0, 0, 2), $c('Death Coil', 'fas fa-skull', 'spell', '1d20+5', '2d6'), $c('Anti-Magic Shell', 'fas fa-shield', 'ability', null, null, 8)],
            'Demon Hunter' => [$c('Chaos Strike', 'fas fa-fire', 'ability', '1d20+6', '2d10'), $c('Eye Beam', 'fas fa-eye', 'ability', '1d20+6', '3d8', 0, 0, 2), $c('Fel Rush', 'fas fa-bolt', 'ability', '1d20+4', '1d8'), $c('Blur', 'fas fa-wind', 'ability', null, null, 6)],
            'Evoker' => [$c('Living Flame', 'fas fa-fire-flame-curved', 'spell', '1d20+5', '2d8'), $c('Disintegrate', 'fas fa-bolt', 'spell', '1d20+6', '3d8', 0, 0, 2), $c('Fire Breath', 'fas fa-dragon', 'spell', '1d20+5', '2d10', 0, 0, 2), $c('Emerald Blossom', 'fas fa-leaf', 'spell', null, null, 0, 12, 2)],
        ];

        return $sets[$class] ?? [
            $c('Strike', 'fas fa-khanda', 'ability', '1d20+4', '2d6'),
            $c('Power Attack', 'fas fa-burst', 'ability', '1d20+5', '2d8', 0, 0, 2),
            $c('Guard', 'fas fa-shield', 'ability', null, null, 5),
            $c('Recover', 'fas fa-plus', 'ability', null, null, 0, 8),
        ];
    }

    // ── Arena integration (forumaker/arena) ────────────────────────────────

    public function arenaInstalled(): bool
    {
        $s = $this->db->getSchemaBuilder();

        return $s->hasTable('arena_cards') && $s->hasTable('arena_decks') && $s->hasTable('arena_stats');
    }

    /**
     * Import a WoW character into Arena: ensure a set of class-themed cards
     * exists in the shared card pool (idempotent by name), build the member's
     * deck from them, and scale their HP/mana from item level + primary stats.
     * Mirrors {@see toRoleplay()} for the card-based PvP extension.
     */
    public function toArena(int $userId, int $id): array
    {
        if (! $this->arenaInstalled()) {
            return ['ok' => false, 'error' => 'Arena is not installed on this forum.'];
        }
        $c = ArmoryCharacter::query()->where('id', $id)->where('user_id', $userId)->first();
        if (! $c) {
            return ['ok' => false, 'error' => 'Character not found.'];
        }

        $sets = $this->arenaClassCards((string) $c->class);
        $fill = $this->arenaFillerCards();
        $now = Carbon::now();
        $cardIds = [];

        // A full Arena deck is exactly 20 cards (≤10 elemental + ≤10 bonus) — that's
        // what CreateBattleController requires to start a challenge. Combine the
        // class's signature cards with shared filler and cap each type at 10.
        $cap = function (array $primary, array $filler, int $n): array {
            $out = [];
            $seen = [];
            foreach (array_merge($primary, $filler) as $card) {
                if (isset($seen[$card[0]])) {
                    continue;
                }
                $seen[$card[0]] = true;
                $out[] = $card;
                if (count($out) >= $n) {
                    break;
                }
            }

            return $out;
        };
        $elemental = $cap($sets['elemental'], $fill['elemental'], 10);
        $bonusCards = $cap($sets['bonus'], $fill['bonus'], 10);

        // Attack/defense derived from cost tier for consistent balance.
        $tier = [1 => [2, 2], 2 => [4, 3], 3 => [6, 4]];

        foreach ($elemental as $card) {
            [$name, $element, $cost] = $card;
            $cardIds[] = $this->ensureArenaCard($name, [
                'element' => $element,
                'card_type' => 'elemental',
                'attack' => $tier[$cost][0],
                'defense' => $tier[$cost][1],
                'cost' => $cost,
            ], $now);
        }
        foreach ($bonusCards as $card) {
            [$name, $effect, $value] = $card;
            $cardIds[] = $this->ensureArenaCard($name, [
                'element' => 'fire', // ignored for bonus cards; column is NOT NULL-safe default
                'card_type' => 'bonus',
                'attack' => 0,
                'defense' => 0,
                'cost' => 2,
                'bonus_effect' => $effect,
                'bonus_value' => $value,
            ], $now);
        }

        // Rebuild the member's deck from the generated class cards.
        $this->db->table('arena_decks')->where('user_id', $userId)->delete();
        $pos = 0;
        foreach (array_values(array_unique($cardIds)) as $cid) {
            $this->db->table('arena_decks')->insert([
                'user_id' => $userId,
                'card_id' => $cid,
                'position' => $pos++,
            ]);
        }

        // Scale HP/mana from gear (gentle — keeps PvP balanced): defaults 15/20.
        $stats = $this->full($id)['stats'] ?? [];
        $prim = 0;
        foreach ($stats['primary'] ?? [] as $p) {
            $prim = max($prim, (int) $p[1]);
        }
        $ilvl = (int) ($c->item_level ?: 0);
        $clamp = fn ($v, $lo, $hi) => (int) max($lo, min($hi, round($v)));
        $this->db->table('arena_stats')->updateOrInsert(
            ['user_id' => $userId],
            [
                'max_hp' => $clamp(15 + $ilvl / 30, 15, 35),
                'max_mana' => $clamp(20 + $prim / 400, 20, 35),
            ]
        );

        return ['ok' => true, 'cards' => count($cardIds), 'character' => $c->name];
    }

    /** Insert an Arena card by name if missing; return its id. */
    private function ensureArenaCard(string $name, array $attrs, Carbon $now): int
    {
        $existing = $this->db->table('arena_cards')->where('name', $name)->value('id');
        if ($existing) {
            return (int) $existing;
        }

        // NB: Arena's ArenaCard model has timestamps disabled and stores created_at
        // as NULL — its GetCardsController does `created_at?->toIso8601String()`,
        // which fatals on a non-null string. So we leave the timestamps NULL too.
        return (int) $this->db->table('arena_cards')->insertGetId(array_merge([
            'name' => $name,
            'description' => null,
            'image_url' => null,
            'order' => 900,
            'is_active' => true,
        ], $attrs));
    }

    /** Class-themed Arena cards: 6 elemental [name, element, cost] + 4 bonus [name, effect, value]. */
    private function arenaClassCards(string $class): array
    {
        $sets = [
            'Death Knight' => ['elemental' => [['Frost Strike', 'water', 2], ['Obliterate', 'water', 3], ['Death Coil', 'earth', 2], ['Festering Strike', 'earth', 1], ['Howling Blast', 'water', 1], ['Scourge Strike', 'earth', 3]], 'bonus' => [['Death Strike', 'heal', 3], ['Anti-Magic Shell', 'shield', 3], ['Chains of Ice', 'weaken', 2], ['Blood Boil', 'poison', 2]]],
            'Demon Hunter' => ['elemental' => [['Chaos Strike', 'fire', 2], ['Fel Rush', 'wind', 1], ['Eye Beam', 'fire', 3], ['Blade Dance', 'wind', 2], ['Immolation Aura', 'fire', 1], ['Death Sweep', 'wind', 3]], 'bonus' => [['Blur', 'shield', 3], ['Consume Magic', 'debuff_remove', 0], ['Metamorphosis', 'power_surge', 2], ['Sigil of Flame', 'poison', 2]]],
            'Druid' => ['elemental' => [['Wrath', 'earth', 2], ['Starsurge', 'wind', 3], ['Moonfire', 'wind', 1], ['Mangle', 'earth', 2], ['Sunfire', 'fire', 1], ['Ferocious Bite', 'earth', 3]], 'bonus' => [['Regrowth', 'heal', 3], ['Rejuvenation', 'regeneration', 2], ['Barkskin', 'shield', 3], ['Entangling Roots', 'weaken', 2]]],
            'Evoker' => ['elemental' => [['Living Flame', 'fire', 2], ['Disintegrate', 'fire', 3], ['Azure Strike', 'water', 1], ['Fire Breath', 'fire', 3], ['Eternity Surge', 'wind', 2], ['Pyre', 'fire', 1]], 'bonus' => [['Emerald Blossom', 'heal', 3], ['Dream Breath', 'regeneration', 2], ['Obsidian Scales', 'shield', 3], ['Oppressing Roar', 'weaken', 2]]],
            'Hunter' => ['elemental' => [['Aimed Shot', 'wind', 3], ['Arcane Shot', 'wind', 1], ['Kill Command', 'earth', 2], ['Multi-Shot', 'wind', 2], ['Explosive Shot', 'fire', 2], ['Steady Shot', 'wind', 1]], 'bonus' => [['Exhilaration', 'heal', 3], ['Feign Death', 'debuff_remove', 0], ['Aspect of the Turtle', 'shield', 3], ['Serpent Sting', 'poison', 2]]],
            'Mage' => ['elemental' => [['Frostbolt', 'water', 2], ['Fireball', 'fire', 2], ['Arcane Blast', 'wind', 3], ['Ice Lance', 'water', 1], ['Pyroblast', 'fire', 3], ['Arcane Missiles', 'wind', 1]], 'bonus' => [['Ice Barrier', 'shield', 3], ['Mana Shield', 'mana_steal', 2], ['Amplify Magic', 'amplify', 2], ['Combustion', 'power_surge', 2]]],
            'Monk' => ['elemental' => [['Tiger Palm', 'wind', 1], ['Rising Sun Kick', 'wind', 3], ['Blackout Kick', 'wind', 2], ['Spinning Crane Kick', 'wind', 2], ['Crackling Jade Lightning', 'lightning', 2], ['Fists of Fury', 'wind', 3]], 'bonus' => [['Vivify', 'heal', 3], ['Fortifying Brew', 'shield', 3], ['Renewing Mist', 'regeneration', 2], ['Paralysis', 'weaken', 2]]],
            'Paladin' => ['elemental' => [['Crusader Strike', 'fire', 1], ["Templar's Verdict", 'fire', 3], ['Judgment', 'fire', 2], ['Blade of Justice', 'earth', 2], ['Hammer of Wrath', 'fire', 2], ['Consecration', 'earth', 1]], 'bonus' => [['Holy Light', 'heal', 4], ['Divine Shield', 'shield', 4], ['Word of Glory', 'regeneration', 2], ['Blessing of Protection', 'debuff_remove', 0]]],
            'Priest' => ['elemental' => [['Smite', 'water', 1], ['Holy Fire', 'fire', 2], ['Shadow Word: Pain', 'earth', 1], ['Mind Blast', 'earth', 2], ['Penance', 'water', 3], ['Mind Flay', 'earth', 2]], 'bonus' => [['Heal', 'heal', 4], ['Power Word: Shield', 'shield', 3], ['Renew', 'regeneration', 2], ['Vampiric Touch', 'mana_steal', 2]]],
            'Rogue' => ['elemental' => [['Sinister Strike', 'wind', 1], ['Eviscerate', 'wind', 3], ['Ambush', 'earth', 2], ['Backstab', 'wind', 2], ['Mutilate', 'water', 2], ['Envenom', 'earth', 3]], 'bonus' => [['Crimson Vial', 'heal', 2], ['Cloak of Shadows', 'debuff_remove', 0], ['Deadly Poison', 'poison', 3], ['Evasion', 'shield', 3]]],
            'Shaman' => ['elemental' => [['Lightning Bolt', 'lightning', 2], ['Lava Burst', 'fire', 3], ['Chain Lightning', 'lightning', 2], ['Earth Shock', 'earth', 1], ['Frost Shock', 'water', 1], ['Stormstrike', 'lightning', 3]], 'bonus' => [['Healing Surge', 'heal', 3], ['Earth Shield', 'shield', 3], ['Riptide', 'regeneration', 2], ['Hex', 'weaken', 2]]],
            'Warlock' => ['elemental' => [['Shadow Bolt', 'earth', 2], ['Chaos Bolt', 'fire', 3], ['Incinerate', 'fire', 2], ['Immolate', 'fire', 1], ['Haunt', 'earth', 2], ['Malefic Rapture', 'earth', 3]], 'bonus' => [['Drain Life', 'mana_steal', 2], ['Unstable Affliction', 'poison', 3], ['Dark Pact', 'shield', 3], ['Curse of Weakness', 'weaken', 2]]],
            'Warrior' => ['elemental' => [['Mortal Strike', 'earth', 2], ['Execute', 'earth', 3], ['Bloodthirst', 'fire', 2], ['Slam', 'earth', 1], ['Rampage', 'fire', 3], ['Overpower', 'wind', 1]], 'bonus' => [['Shield Wall', 'shield', 4], ['Enraged Regeneration', 'heal', 3], ['Battle Shout', 'power_surge', 2], ['Hamstring', 'weaken', 2]]],
        ];

        return $sets[$class] ?? [
            'elemental' => [['Strike', 'earth', 1], ['Heavy Blow', 'earth', 2], ['Cleave', 'wind', 2], ['Crushing Blow', 'earth', 3], ['Quick Jab', 'wind', 1], ['Finisher', 'fire', 3]],
            'bonus' => [['Second Wind', 'heal', 3], ['Brace', 'shield', 3], ['Rally', 'power_surge', 2], ['Cripple', 'weaken', 2]],
        ];
    }

    /** Shared filler cards that top a class's signature cards up to a full 20-card deck (10 elemental + 10 bonus). */
    private function arenaFillerCards(): array
    {
        return [
            'elemental' => [
                ['Ember', 'fire', 1], ['Gust', 'wind', 2], ['Tremor', 'earth', 2],
                ['Tidal Wave', 'water', 3], ['Spark', 'lightning', 1], ['Boulder', 'earth', 3],
            ],
            'bonus' => [
                ['Empower', 'amplify', 2], ['Overcharge', 'overload', 2], ['Attunement', 'resonance', 2],
                ['Blood Offering', 'sacrifice', 2], ['Siphon', 'mana_steal', 2], ['Mirror Ward', 'reflect', 2],
                ['Renewal', 'regeneration', 2], ['Cleanse', 'debuff_remove', 0],
            ],
        ];
    }
}
