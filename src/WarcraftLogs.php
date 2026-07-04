<?php

namespace ErnestDefoe\Armory;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Store;
use Psr\Log\LoggerInterface;

/**
 * Minimal Warcraft Logs v2 client (GraphQL, client-credentials flow).
 * Public guild reports only — no user OAuth. Every method degrades to
 * null/[] on failure; callers treat WCL as an optional data source.
 */
class WarcraftLogs
{
    protected const TOKEN_URL = 'https://www.warcraftlogs.com/oauth/token';
    protected const API_URL = 'https://www.warcraftlogs.com/api/v2/client';

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Store $cache,
        protected LoggerInterface $log,
    ) {
    }

    public function configured(): bool
    {
        return trim((string) $this->settings->get('armory.wcl_client_id')) !== ''
            && trim((string) $this->settings->get('armory.wcl_client_secret')) !== '';
    }

    /**
     * Recent guild reports, newest first. Each: code, title, startTime,
     * endTime (ms epoch), zone name, and the encounter fights
     * (name, difficulty, kill, bossPercentage).
     */
    public function recentReports(string $guild, string $serverSlug, string $region, int $limit = 3): array
    {
        $query = <<<'GQL'
        query ($name: String!, $slug: String!, $region: String!, $limit: Int!) {
          reportData {
            reports(guildName: $name, guildServerSlug: $slug, guildServerRegion: $region, limit: $limit) {
              data {
                code
                title
                startTime
                endTime
                zone { name }
                fights (killType: Encounters) {
                  name
                  difficulty
                  kill
                  bossPercentage
                }
              }
            }
          }
        }
        GQL;

        $data = $this->graphql($query, [
            'name' => $guild,
            'slug' => strtolower($serverSlug),
            'region' => strtolower($region),
            'limit' => $limit,
        ]);

        $reports = $data['reportData']['reports']['data'] ?? null;

        return is_array($reports) ? array_values(array_filter($reports, 'is_array')) : [];
    }

    /**
     * The rankings blob for one report (per-fight parse data), or null.
     * WCL returns this as an opaque JSON scalar.
     */
    public function reportRankings(string $code): ?array
    {
        $query = <<<'GQL'
        query ($code: String!) {
          reportData {
            report(code: $code) {
              rankings(playerMetric: dps)
            }
          }
        }
        GQL;

        $data = $this->graphql($query, ['code' => $code]);
        $rankings = $data['reportData']['report']['rankings'] ?? null;

        return is_array($rankings) ? $rankings : null;
    }

    /** ---- plumbing ------------------------------------------------- */

    protected function graphql(string $query, array $variables): ?array
    {
        $token = $this->token();
        if (! $token) {
            return null;
        }

        try {
            $http = new Client(['timeout' => 12, 'http_errors' => false]);
            $r = $http->post(self::API_URL, [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'json' => ['query' => $query, 'variables' => $variables],
            ]);

            $body = json_decode((string) $r->getBody(), true);
            if ($r->getStatusCode() !== 200 || ! is_array($body)) {
                $this->log->warning('[armory] WCL query failed', ['status' => $r->getStatusCode()]);

                return null;
            }
            if (! empty($body['errors'])) {
                $this->log->warning('[armory] WCL GraphQL errors', [
                    'first' => $body['errors'][0]['message'] ?? 'unknown',
                ]);
            }

            return is_array($body['data'] ?? null) ? $body['data'] : null;
        } catch (\Throwable $e) {
            $this->log->warning('[armory] WCL request error: '.$e->getMessage());

            return null;
        }
    }

    protected function token(): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        $cached = $this->cache->get('armory.wcl_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $http = new Client(['timeout' => 12, 'http_errors' => false]);
            $r = $http->post(self::TOKEN_URL, [
                'auth' => [
                    trim((string) $this->settings->get('armory.wcl_client_id')),
                    trim((string) $this->settings->get('armory.wcl_client_secret')),
                ],
                'form_params' => ['grant_type' => 'client_credentials'],
            ]);

            $body = json_decode((string) $r->getBody(), true);
            $token = is_array($body) ? (string) ($body['access_token'] ?? '') : '';
            if ($r->getStatusCode() !== 200 || $token === '') {
                $this->log->warning('[armory] WCL token request failed', ['status' => $r->getStatusCode()]);

                return null;
            }

            $ttl = max(60, min((int) ($body['expires_in'] ?? 3600) - 120, 86400));
            $this->cache->put('armory.wcl_token', $token, $ttl);

            return $token;
        } catch (\Throwable $e) {
            $this->log->warning('[armory] WCL token error: '.$e->getMessage());

            return null;
        }
    }
}
