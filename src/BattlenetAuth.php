<?php

namespace ErnestDefoe\Armory;

use Carbon\Carbon;
use ErnestDefoe\Armory\Job\SyncCharactersJob;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Queue\Queue;
use Psr\Log\LoggerInterface;

/**
 * Battle.net OAuth: stateless signed state tokens, code exchange, linking a
 * Battle.net account to a forum user, and encrypting the stored access token at
 * rest (libsodium; key in a file under storage/, outside the DB, so a DB dump
 * alone can't decrypt linked users' tokens).
 */
class BattlenetAuth
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected BlizzardApi $api,
        protected LoggerInterface $logger,
        protected Paths $paths,
        protected Queue $queue,
        protected ?Store $cache = null,
    ) {
    }

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

    public function encrypt(string $plain): string
    {
        $key = $this->tokenKey();
        if ($key === null) {
            return $plain; // never lose the token; fall back to storing as-is
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'sb1:'.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $key));
    }

    public function decrypt(?string $stored): ?string
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

    // ── OAuth state (two modes: 'login' = social sign-in, 'link' = connect armory) ──

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
                'access_token' => $this->encrypt((string) $token['access_token']),
                'token_expires_at' => isset($token['expires_in']) ? Carbon::now()->addSeconds((int) $token['expires_in']) : null,
            ]
        );
        // Character enrichment makes ~60 sequential Blizzard calls — run it off the
        // request cycle so it never blocks the OAuth callback / login redirect.
        $this->queue->push(new SyncCharactersJob($userId));

        return true;
    }

    /**
     * Complete a social-signup link lazily: the OAuth callback caches the
     * exchanged token per Battle.net id (the registration modal finishes in a
     * later request, when the token is long gone), and the first authenticated
     * armory visit claims it — so brand-new members arrive with their
     * characters already syncing, no second OAuth hop.
     */
    public function completePendingSocialLink(User $user): void
    {
        if (ArmoryBattlenetAccount::query()->where('user_id', $user->id)->exists()) {
            return;
        }
        $provider = $user->loginProviders()->where('provider', 'battlenet')->first();
        if (! $provider) {
            return;
        }
        $cache = $this->cache;
        if (! $cache) {
            return;
        }
        $key = 'armory.pending_link.'.$provider->identifier;
        $pending = $cache->get($key);
        if (is_array($pending) && is_array($pending['token'] ?? null)) {
            if ($this->storeLink((int) $user->id, $pending['token'], (array) ($pending['info'] ?? []))) {
                $cache->forget($key);
            }
        }
    }
}
