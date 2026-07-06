<?php

namespace ErnestDefoe\Armory;

use Carbon\Carbon;

/**
 * Character sync (list an account's characters + enrich each with detail/media
 * from Blizzard) and the member's own roster mutations (main/visibility/
 * disconnect). Reads the linked account's OAuth token via BattlenetAuth.
 */
class CharacterSync
{
    public function __construct(
        protected BlizzardApi $api,
        protected BattlenetAuth $auth,
    ) {
    }

    public function sync(int $userId): array
    {
        $acct = ArmoryBattlenetAccount::query()->where('user_id', $userId)->first();
        $accessToken = $this->auth->decrypt($acct->access_token ?? null);
        if (! $acct || ! $accessToken) {
            return ['ok' => false, 'reason' => 'not_linked'];
        }
        $region = $acct->region ?: $this->api->region();

        // Listing the account's characters needs the user's OAuth token, which
        // lasts ~24h with no refresh. Once it's expired we can't discover NEW
        // characters (or re-list at all) — so signal the UI to re-authenticate
        // with Battle.net for a fresh token instead of silently returning the
        // stale roster (which reads as "it's not finding my new characters").
        if ($acct->token_expires_at && Carbon::parse($acct->token_expires_at)->isPast()) {
            return ['ok' => false, 'reason' => 'reauth'];
        }

        $profile = $this->api->accountProfile($accessToken, $region);
        if (! $profile) {
            return ['ok' => false, 'reason' => 'reauth'];
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

    /**
     * Cheap synchronous pre-flight for the Sync endpoint — replicates sync()'s
     * link + token checks WITHOUT any Blizzard call, so the controller can send
     * the UI straight to re-auth instead of queueing a job that would no-op.
     * Returns null when a background sync should proceed.
     */
    public function syncGate(int $userId): ?array
    {
        $acct = ArmoryBattlenetAccount::query()->where('user_id', $userId)->first();
        $accessToken = $this->auth->decrypt($acct->access_token ?? null);
        if (! $acct || ! $accessToken) {
            return ['ok' => false, 'reason' => 'not_linked'];
        }
        if ($acct->token_expires_at && Carbon::parse($acct->token_expires_at)->isPast()) {
            return ['ok' => false, 'reason' => 'reauth'];
        }

        return null;
    }

    /** ISO-8601 timestamp of the member's last completed character sync, or null. */
    public function lastSyncedAt(int $userId): ?string
    {
        $acct = ArmoryBattlenetAccount::query()->where('user_id', $userId)->first();

        return optional($acct)->synced_at?->toIso8601String();
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
        // An explicit pick ends the "choose your primary character" onboarding.
        ArmoryBattlenetAccount::query()->where('user_id', $userId)->update(['main_confirmed' => true]);

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
}
