<?php

namespace ErnestDefoe\Armory;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Illuminate\Database\ConnectionInterface;

/**
 * Soft integration with forumaker/arena: import a WoW character into Arena —
 * ensure a set of class-themed cards exists in the shared pool (idempotent by
 * name), build the member's 20-card deck, and scale their HP/mana from item
 * level + primary stats. Mirrors RoleplayImporter for the card-based PvP ext.
 */
class ArenaImporter
{
    public function __construct(
        protected ConnectionInterface $db,
        protected ExtensionManager $extensions,
        protected CharacterSheet $sheet,
    ) {
    }

    public function installed(): bool
    {
        if (! $this->extensions->isEnabled('forumaker-arena')) {
            return false;
        }

        $s = $this->db->getSchemaBuilder();

        return $s->hasTable('arena_cards') && $s->hasTable('arena_decks') && $s->hasTable('arena_stats');
    }

    /**
     * Import a WoW character into Arena: ensure a set of class-themed cards
     * exists in the shared card pool (idempotent by name), build the member's
     * deck from them, and scale their HP/mana from item level + primary stats.
     */
    public function import(int $userId, int $id): array
    {
        if (! $this->installed()) {
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

        // Rebuild the member's deck from the generated class cards. Atomic so a
        // mid-insert failure can't leave a partial deck. (arena_decks assumed
        // cols: user_id, card_id, position.)
        $this->db->transaction(function () use ($userId, $cardIds) {
            $this->db->table('arena_decks')->where('user_id', $userId)->delete();
            $pos = 0;
            foreach (array_values(array_unique($cardIds)) as $cid) {
                $this->db->table('arena_decks')->insert([
                    'user_id' => $userId,
                    'card_id' => $cid,
                    'position' => $pos++,
                ]);
            }
        });

        // Scale HP/mana from gear (gentle — keeps PvP balanced): defaults 15/20.
        $stats = $this->sheet->full($id)['stats'] ?? [];
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
