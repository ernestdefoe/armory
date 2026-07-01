<?php

namespace ErnestDefoe\Armory\Controller;

use ErnestDefoe\Armory\Armory;
use ErnestDefoe\Armory\ArmoryCharacter;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The configured guild's full member roster, from Blizzard's guild profile
 * API (client-credentials — works without any member linking Battle.net).
 * Public data (Blizzard serves it to anyone with an API key), cached an hour
 * so a busy forum never hammers the endpoint.
 */
class GuildRosterController implements RequestHandlerInterface
{
    /** playable_class id → class name (static per game build). */
    private const CLASSES = [
        1 => 'Warrior', 2 => 'Paladin', 3 => 'Hunter', 4 => 'Rogue',
        5 => 'Priest', 6 => 'Death Knight', 7 => 'Shaman', 8 => 'Mage',
        9 => 'Warlock', 10 => 'Monk', 11 => 'Druid', 12 => 'Demon Hunter',
        13 => 'Evoker',
    ];

    public function __construct(
        protected Armory $armory,
        protected SettingsRepositoryInterface $settings,
        protected Cache $cache,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $realm = trim((string) $this->settings->get('armory.guild_realm'));
        $name = trim((string) $this->settings->get('armory.guild_name'));

        if ($realm === '' || $name === '') {
            return new JsonResponse(['ok' => false, 'error' => 'not_configured']);
        }

        $region = $this->armory->api()->region();
        $realmSlug = $this->slug($realm);
        $guildSlug = $this->slug($name);

        $data = $this->cache->remember(
            "armory.guild_roster.{$region}.{$realmSlug}.{$guildSlug}",
            3600,
            fn () => $this->fetch($region, $realmSlug, $guildSlug)
        );

        if (! is_array($data)) {
            return new JsonResponse(['ok' => false, 'error' => 'unavailable']);
        }

        return new JsonResponse(['ok' => true] + $data);
    }

    private function fetch(string $region, string $realmSlug, string $guildSlug): ?array
    {
        $raw = $this->armory->api()->guildRoster($region, $realmSlug, $guildSlug);

        // Connected realms: the guild entity lives on its CREATION realm's slug,
        // which is often not the realm members play on (e.g. a guild on
        // Destromath actually resolves under thunderlord). If the configured
        // realm misses, discover the canonical slug from a synced member's
        // character profile and retry once.
        if (! is_array($raw)) {
            $canonical = $this->canonicalRealm($region, $guildSlug, $realmSlug);
            if ($canonical !== null && $canonical !== $realmSlug) {
                $raw = $this->armory->api()->guildRoster($region, $canonical, $guildSlug);
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
                'class' => self::CLASSES[(int) ($c['playable_class']['id'] ?? 0)] ?? null,
                'rank' => (int) ($m['rank'] ?? 99),
            ];
        }

        usort($members, fn ($a, $b) => [$a['rank'], -$a['level'], $a['name']] <=> [$b['rank'], -$b['level'], $b['name']]);

        return [
            'guild' => (string) ($raw['guild']['name'] ?? ''),
            'realm' => (string) ($raw['guild']['realm']['slug'] ?? $realmSlug),
            'members' => $members,
        ];
    }

    /**
     * Resolve the guild's canonical realm slug by asking Blizzard about a
     * synced character that belongs to the guild. Cached a day — the answer
     * only changes on realm connections, which are rare.
     */
    private function canonicalRealm(string $region, string $guildSlug, string $configuredRealm): ?string
    {
        return $this->cache->remember(
            "armory.guild_canonical_realm.{$region}.{$guildSlug}",
            86400,
            function () use ($region, $guildSlug) {
                $member = ArmoryCharacter::query()
                    ->whereNotNull('guild')
                    ->where('region', $region)
                    ->get(['name', 'realm_slug', 'guild'])
                    ->first(fn ($c) => $this->slug((string) $c->guild) === $guildSlug);

                if (! $member) {
                    return null;
                }

                $profile = $this->armory->api()->character($region, $member->realm_slug, mb_strtolower($member->name));

                return $profile['guild']['realm']['slug'] ?? null;
            }
        );
    }

    /** Blizzard slug: lowercase, apostrophes dropped, spaces become dashes. */
    private function slug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(["'", "\u{2019}"], '', $value);

        return preg_replace('/\s+/', '-', $value) ?? $value;
    }
}
