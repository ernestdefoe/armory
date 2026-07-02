<?php

namespace ErnestDefoe\Armory;

/**
 * The canonical WoW playable-class catalog: Blizzard playable-class ids plus
 * display names, keyed by a normalized slug. Used to parse the admin's
 * "recruiting" list (forgiving input — "Death Knight", "death-knight" and
 * "deathknight" all match) and to fetch each class's official icon from the
 * static media API.
 */
class PlayableClasses
{
    /** slug => [Blizzard playable-class id, display name] */
    public const ALL = [
        'warrior'     => [1, 'Warrior'],
        'paladin'     => [2, 'Paladin'],
        'hunter'      => [3, 'Hunter'],
        'rogue'       => [4, 'Rogue'],
        'priest'      => [5, 'Priest'],
        'deathknight' => [6, 'Death Knight'],
        'shaman'      => [7, 'Shaman'],
        'mage'        => [8, 'Mage'],
        'warlock'     => [9, 'Warlock'],
        'monk'        => [10, 'Monk'],
        'druid'       => [11, 'Druid'],
        'demonhunter' => [12, 'Demon Hunter'],
        'evoker'      => [13, 'Evoker'],
    ];

    /** Normalize any reasonable spelling to a catalog slug ('' when unknown). */
    public static function slugFor(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-z]/i', '', $name) ?? '');

        return isset(self::ALL[$slug]) ? $slug : '';
    }

    /**
     * Parse the admin's recruiting textarea — one class per line, optionally
     * followed by ": note" (e.g. "shaman: Resto or Elemental"). Unknown class
     * lines are skipped; duplicates keep the first note.
     *
     * @return array<int, array{slug: string, id: int, name: string, note: string}>
     */
    public static function parseRecruiting(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$class, $note] = array_pad(explode(':', $line, 2), 2, '');
            $slug = self::slugFor($class);
            if ($slug === '' || isset($out[$slug])) {
                continue;
            }
            [$id, $name] = self::ALL[$slug];
            $out[$slug] = [
                'slug' => $slug,
                'id'   => $id,
                'name' => $name,
                'note' => mb_substr(trim($note), 0, 120),
            ];
        }

        return array_values($out);
    }
}
