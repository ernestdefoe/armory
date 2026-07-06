<?php

namespace ErnestDefoe\Armory;

use Flarum\User\User;

/**
 * Thin façade over the armory services, preserving the public interface the
 * controllers, Briefing and GuildNews depend on. Each concern lives in its own
 * injectable service:
 *   - {@see BattlenetAuth}    OAuth state, code exchange, account linking, token crypto
 *   - {@see CharacterSync}    character sync + the member's roster mutations
 *   - {@see CharacterSheet}   the armory page tabs + item card + by-name lookups
 *   - {@see GuildRoster}      guild roster + activity feed + membership gate
 *   - {@see RoleplayImporter} ernestdefoe/roleplay import
 *   - {@see ArenaImporter}    forumaker/arena import
 */
class Armory
{
    public function __construct(
        protected BlizzardApi $api,
        protected BattlenetAuth $auth,
        protected CharacterSync $sync,
        protected CharacterSheet $sheet,
        protected GuildRoster $guild,
        protected RoleplayImporter $roleplay,
        protected ArenaImporter $arena,
    ) {
    }

    public function api(): BlizzardApi
    {
        return $this->api;
    }

    public function config(): array
    {
        return ['configured' => $this->api->configured(), 'region' => $this->api->region()];
    }

    // ── OAuth / linking (BattlenetAuth) ──────────────────────────────────────

    public function signState(string $mode = 'link', string $returnTo = '/'): string
    {
        return $this->auth->signState($mode, $returnTo);
    }

    public function readState(string $state): ?array
    {
        return $this->auth->readState($state);
    }

    public function verifyState(string $state): bool
    {
        return $this->auth->verifyState($state);
    }

    public function completeLink(int $userId, string $code, string $redirectUri): bool
    {
        return $this->auth->completeLink($userId, $code, $redirectUri);
    }

    public function storeLink(int $userId, array $token, array $info): bool
    {
        return $this->auth->storeLink($userId, $token, $info);
    }

    public function completePendingSocialLink(User $user): void
    {
        $this->auth->completePendingSocialLink($user);
    }

    // ── Member view ──────────────────────────────────────────────────────────

    public function me(User $user): array
    {
        $this->auth->completePendingSocialLink($user);

        $acct = ArmoryBattlenetAccount::query()->where('user_id', $user->id)->first();

        return [
            'configured' => $this->api->configured(),
            'connected' => (bool) $acct,
            'main_confirmed' => (bool) ($acct->main_confirmed ?? false),
            'battletag' => $acct->battletag ?? null,
            'region' => $acct->region ?? $this->api->region(),
            'synced_at' => optional($acct)->synced_at?->toIso8601String(),
            'rp_installed' => $this->roleplay->installed(),
            'arena_installed' => $this->arena->installed(),
            'characters' => $this->sync->characters((int) $user->id),
        ];
    }

    public function characters(int $userId): array
    {
        return $this->sync->characters($userId);
    }

    public function visibleCharacters(int $userId): array
    {
        return $this->sync->visibleCharacters($userId);
    }

    public function setMain(int $userId, int $charId): bool
    {
        return $this->sync->setMain($userId, $charId);
    }

    public function setVisible(int $userId, int $charId): bool
    {
        return $this->sync->setVisible($userId, $charId);
    }

    public function disconnect(int $userId): void
    {
        $this->sync->disconnect($userId);
    }

    // ── Sync (CharacterSync) ─────────────────────────────────────────────────

    public function sync(int $userId): array
    {
        return $this->sync->sync($userId);
    }

    public function syncGate(int $userId): ?array
    {
        return $this->sync->syncGate($userId);
    }

    public function lastSyncedAt(int $userId): ?string
    {
        return $this->sync->lastSyncedAt($userId);
    }

    // ── Character sheet + lookups (CharacterSheet) ───────────────────────────

    public function full(int $id): array
    {
        return $this->sheet->full($id);
    }

    public function extra(int $id, string $kind): array
    {
        return $this->sheet->extra($id, $kind);
    }

    public function fullByName(string $realmSlug, string $name): array
    {
        return $this->sheet->fullByName($realmSlug, $name);
    }

    public function extraByName(string $realmSlug, string $name, string $kind): array
    {
        return $this->sheet->extraByName($realmSlug, $name, $kind);
    }

    public function itemCard(int $id): array
    {
        return $this->sheet->itemCard($id);
    }

    public function searchItems(string $q): array
    {
        return $this->sheet->searchItems($q);
    }

    // ── Guild (GuildRoster) ──────────────────────────────────────────────────

    public function guildRoster(bool $fresh = false): ?array
    {
        return $this->guild->roster($fresh);
    }

    public function guildRecentActivity(int $days = 7): ?array
    {
        return $this->guild->recentActivity($days);
    }

    public function isGuildMember(string $realmSlug, string $name): bool
    {
        return $this->guild->isMember($realmSlug, $name);
    }

    // ── Extension imports (RoleplayImporter / ArenaImporter) ─────────────────

    public function rpInstalled(): bool
    {
        return $this->roleplay->installed();
    }

    public function toRoleplay(int $userId, int $id): array
    {
        return $this->roleplay->import($userId, $id);
    }

    public function arenaInstalled(): bool
    {
        return $this->arena->installed();
    }

    public function toArena(int $userId, int $id): array
    {
        return $this->arena->import($userId, $id);
    }
}
