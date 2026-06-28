<?php

namespace ErnestDefoe\Armory;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Store;

/**
 * Dependency-free client for Battle.net OAuth + the Blizzard WoW API (Guzzle +
 * Flarum's cache). A per-user token (wow.profile) discovers a member's
 * characters once; an app-wide client-credentials token (cached) powers
 * everything else, so refreshes never need the member to sign in again.
 */
class BlizzardApi
{
    public const REGIONS = ['us', 'eu', 'kr', 'tw'];

    protected Client $http;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ?Store $cache = null
    ) {
        $this->http = new Client(['timeout' => 12, 'http_errors' => false]);
    }

    public function clientId(): string
    {
        return trim((string) $this->settings->get('armory.client_id'));
    }

    public function clientSecret(): string
    {
        return trim((string) $this->settings->get('armory.client_secret'));
    }

    public function region(): string
    {
        $r = (string) $this->settings->get('armory.region');

        return in_array($r, self::REGIONS, true) ? $r : 'us';
    }

    public function configured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function oauthHost(): string
    {
        return 'https://oauth.battle.net';
    }

    public function apiHost(string $region): string
    {
        $region = in_array($region, self::REGIONS, true) ? $region : 'us';

        return "https://{$region}.api.blizzard.com";
    }

    public function clientToken(): ?string
    {
        if (! $this->configured()) {
            return null;
        }
        $cached = $this->cache?->get('armory.client_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        try {
            $r = $this->http->post($this->oauthHost().'/token', [
                'auth' => [$this->clientId(), $this->clientSecret()],
                'form_params' => ['grant_type' => 'client_credentials'],
            ]);
            $tok = $this->body($r)['access_token'] ?? null;
            if ($tok) {
                $this->cache?->put('armory.client_token', $tok, 82800);
            }

            return $tok;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function exchangeCode(string $code, string $redirectUri): ?array
    {
        try {
            $r = $this->http->post($this->oauthHost().'/token', [
                'auth' => [$this->clientId(), $this->clientSecret()],
                'form_params' => ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri],
            ]);

            return $this->body($r);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function userInfo(string $userToken): ?array
    {
        return $this->getJson($this->oauthHost().'/userinfo', $userToken);
    }

    /** The member's WoW account summary (their characters). Needs the user token. */
    public function accountProfile(string $userToken, string $region): ?array
    {
        return $this->getJson($this->apiHost($region).'/profile/user/wow', $userToken, [
            'namespace' => "profile-{$region}", 'locale' => 'en_US',
        ]);
    }

    public function character(string $r, string $rs, string $n): ?array
    {
        return $this->profileGet($r, $rs, $n, '');
    }

    public function characterMedia(string $r, string $rs, string $n): ?array
    {
        return $this->profileGet($r, $rs, $n, '/character-media');
    }

    public function equipment(string $r, string $rs, string $n): ?array
    {
        return $this->profileGet($r, $rs, $n, '/equipment');
    }

    public function statistics(string $r, string $rs, string $n): ?array
    {
        return $this->profileGet($r, $rs, $n, '/statistics');
    }

    public function specializations(string $r, string $rs, string $n): ?array
    {
        return $this->profileGet($r, $rs, $n, '/specializations');
    }

    public function mythicKeystone(string $r, string $rs, string $n): ?array
    {
        return $this->profileGet($r, $rs, $n, '/mythic-keystone-profile');
    }

    public function raids(string $r, string $rs, string $n): ?array
    {
        return $this->profileGet($r, $rs, $n, '/encounters/raids');
    }

    public function professions(string $r, string $rs, string $n): ?array
    {
        return $this->profileGet($r, $rs, $n, '/professions');
    }

    public function pvpSummary(string $r, string $rs, string $n): ?array
    {
        return $this->profileGet($r, $rs, $n, '/pvp-summary');
    }

    public function pvpBracket(string $r, string $rs, string $n, string $bracket): ?array
    {
        return $this->profileGet($r, $rs, $n, '/pvp-bracket/'.$bracket);
    }

    public function reputations(string $r, string $rs, string $n): ?array
    {
        return $this->profileGet($r, $rs, $n, '/reputations');
    }

    public function achievements(string $r, string $rs, string $n): ?array
    {
        return $this->profileGet($r, $rs, $n, '/achievements');
    }

    public function mounts(string $r, string $rs, string $n): ?array
    {
        return $this->profileGet($r, $rs, $n, '/collections/mounts');
    }

    public function pets(string $r, string $rs, string $n): ?array
    {
        return $this->profileGet($r, $rs, $n, '/collections/pets');
    }

    /** Resolve + cache an equipped item's icon URL (icons are static → cache long). */
    public function itemIcon(array $item): ?string
    {
        $href = $item['media']['key']['href'] ?? null;
        $id = $item['media']['id'] ?? ($item['item']['id'] ?? null);
        if (! $href || ! $id) {
            return null;
        }
        $ck = 'armory.icon.'.$id;
        $cached = $this->cache?->get($ck);
        if (is_string($cached)) {
            return $cached;
        }
        $token = $this->clientToken();
        if (! $token) {
            return null;
        }
        $data = $this->getJson($href, $token);
        $url = null;
        foreach ($data['assets'] ?? [] as $a) {
            if (($a['key'] ?? '') === 'icon') {
                $url = $a['value'] ?? null;
                break;
            }
        }
        if ($url) {
            $this->cache?->put($ck, $url, 2592000);
        }

        return $url;
    }

    public function mediaUrl(array $media, string $key = 'avatar'): ?string
    {
        foreach ($media['assets'] ?? [] as $a) {
            if (($a['key'] ?? null) === $key) {
                return $a['value'] ?? null;
            }
        }

        return $media['avatar_url'] ?? null;
    }

    private function profileGet(string $region, string $rs, string $name, string $suffix): ?array
    {
        $token = $this->clientToken();
        if (! $token) {
            return null;
        }
        $path = '/profile/wow/character/'.$rs.'/'.rawurlencode(mb_strtolower($name)).$suffix;

        return $this->getJson($this->apiHost($region).$path, $token, ['namespace' => "profile-{$region}", 'locale' => 'en_US']);
    }

    private function getJson(string $url, string $token, array $query = []): ?array
    {
        try {
            $r = $this->http->get($url, [
                'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'],
                'query' => $query,
            ]);

            return $this->body($r);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function body($response): ?array
    {
        if (! $response) {
            return null;
        }
        $code = $response->getStatusCode();
        if ($code < 200 || $code >= 300) {
            return null;
        }
        $data = json_decode((string) $response->getBody(), true);

        return is_array($data) ? $data : null;
    }
}
