<?php

namespace ErnestDefoe\Armory;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * The guild crafting directory: who can craft what, from the profession
 * data (including known recipes) of linked, visible characters.
 *
 * Per-character profession blobs are cached 12h; the profession overview
 * is cached 1h. Recipe search runs over the cached blobs, so the first
 * directory build warms everything the search needs.
 */
class Crafting
{
    protected const CHAR_TTL = 12 * 3600;
    protected const DIR_TTL = 3600;
    protected const MAX_CHARS = 60;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db,
        protected BlizzardApi $api,
        protected LoggerInterface $log,
        protected ?Store $cache = null,
    ) {
    }

    /** Profession overview: each profession with its crafters, best tier first. */
    public function directory(): array
    {
        $cached = $this->cache?->get('armory.craft.dir');
        if (is_array($cached)) {
            return $cached;
        }

        $byProfession = [];
        foreach ($this->chars() as $ch) {
            foreach ($this->charProfessions($ch) as $prof) {
                $best = $this->bestTier($prof['tiers']);
                $recipes = 0;
                foreach ($prof['tiers'] as $t) {
                    $recipes += count($t['recipes']);
                }

                $byProfession[$prof['name']][] = [
                    'name' => $ch->name,
                    'class' => (string) ($ch->class ?? ''),
                    'realm' => (string) $ch->realm_slug,
                    'userId' => (int) $ch->user_id,
                    'username' => (string) $ch->username,
                    'skill' => $best['skill'],
                    'maxSkill' => $best['max'],
                    'tier' => $best['tier'],
                    'recipes' => $recipes,
                ];
            }
        }

        ksort($byProfession);
        $out = [];
        foreach ($byProfession as $name => $crafters) {
            usort($crafters, fn ($a, $b) => $b['skill'] <=> $a['skill']);
            $out[] = ['profession' => $name, 'crafters' => array_slice($crafters, 0, 20)];
        }

        $this->cache?->put('armory.craft.dir', $out, self::DIR_TTL);

        return $out;
    }

    /** Recipe-name search across every crafter's known recipes. */
    public function search(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $byRecipe = [];
        foreach ($this->chars() as $ch) {
            foreach ($this->charProfessions($ch) as $prof) {
                foreach ($prof['tiers'] as $tier) {
                    foreach ($tier['recipes'] as $recipe) {
                        if (mb_stripos($recipe, $q) === false) {
                            continue;
                        }
                        $byRecipe[$recipe][] = [
                            'name' => $ch->name,
                            'class' => (string) ($ch->class ?? ''),
                            'realm' => (string) $ch->realm_slug,
                            'username' => (string) $ch->username,
                            'profession' => $prof['name'],
                            'tier' => $tier['tier'],
                        ];
                    }
                }
            }
        }

        ksort($byRecipe);
        $out = [];
        foreach (array_slice($byRecipe, 0, 30, true) as $recipe => $crafters) {
            $unique = [];
            foreach ($crafters as $c) {
                $unique[$c['name'].'|'.$c['realm']] = $c;
            }
            $out[] = ['recipe' => $recipe, 'crafters' => array_slice(array_values($unique), 0, 8)];
        }

        return $out;
    }

    /** ---- plumbing ------------------------------------------------- */

    protected function chars(): array
    {
        return $this->db->table('armory_characters')
            ->join('users', 'users.id', '=', 'armory_characters.user_id')
            ->where('armory_characters.is_visible', true)
            ->orderByDesc('armory_characters.is_main')
            ->orderByDesc('armory_characters.item_level')
            ->limit(self::MAX_CHARS)
            ->get([
                'armory_characters.id', 'armory_characters.user_id', 'armory_characters.name',
                'armory_characters.realm_slug', 'armory_characters.region', 'armory_characters.class',
                'users.username',
            ])
            ->all();
    }

    /** @return array<int, array{name: string, tiers: array<int, array{tier: string, skill: int, max: int, recipes: string[]}>}> */
    protected function charProfessions(object $ch): array
    {
        $key = 'armory.craft.char.'.$ch->id;
        $cached = $this->cache?->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $raw = $this->api->professions(
            $ch->region ?: $this->api->region(),
            (string) $ch->realm_slug,
            mb_strtolower((string) $ch->name)
        );

        $profs = [];
        foreach (array_merge((array) ($raw['primaries'] ?? []), (array) ($raw['secondaries'] ?? [])) as $p) {
            $name = (string) ($p['profession']['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $tiers = [];
            foreach ((array) ($p['tiers'] ?? []) as $t) {
                $tiers[] = [
                    'tier' => (string) ($t['tier']['name'] ?? ''),
                    'skill' => (int) ($t['skill_points'] ?? 0),
                    'max' => (int) ($t['max_skill_points'] ?? 0),
                    'recipes' => array_values(array_filter(array_map(
                        fn ($rec) => is_array($rec) ? (string) ($rec['name'] ?? '') : '',
                        (array) ($t['known_recipes'] ?? [])
                    ))),
                ];
            }
            $profs[] = ['name' => $name, 'tiers' => $tiers];
        }

        $this->cache?->put($key, $profs, self::CHAR_TTL);

        return $profs;
    }

    /** @return array{tier: string, skill: int, max: int} */
    protected function bestTier(array $tiers): array
    {
        $best = ['tier' => '', 'skill' => 0, 'max' => 0];
        foreach ($tiers as $t) {
            if ($t['skill'] >= $best['skill']) {
                $best = ['tier' => $t['tier'], 'skill' => $t['skill'], 'max' => $t['max']];
            }
        }

        return $best;
    }
}
