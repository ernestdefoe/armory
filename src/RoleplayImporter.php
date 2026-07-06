<?php

namespace ErnestDefoe\Armory;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Soft integration with ernestdefoe/roleplay: import a WoW character into an
 * rp_character + combat sheet + a deck of signature class ability cards, all
 * scaled from the character's item level / primary stats.
 */
class RoleplayImporter
{
    public function __construct(
        protected ConnectionInterface $db,
        protected ExtensionManager $extensions,
        protected CharacterSheet $sheet,
    ) {
    }

    public function installed(): bool
    {
        // Tables alone aren't proof — a failed/removed install leaves them
        // behind. The extension must actually be enabled.
        if (! $this->extensions->isEnabled('ernestdefoe-roleplay')) {
            return false;
        }

        $s = $this->db->getSchemaBuilder();

        return $s->hasTable('rp_cards') && $s->hasTable('rp_sheets') && $s->hasTable('rp_characters');
    }

    /**
     * Import a WoW character into Role-Play: an rp_character + a combat sheet
     * (HP + attributes scaled from item level / primary stats) + a deck of
     * signature class ability cards (damage dice scaled by item level). Safe to
     * re-run after a gear upgrade — the generated deck is rebuilt cleanly.
     */
    public function import(int $userId, int $id): array
    {
        if (! $this->installed()) {
            return ['ok' => false, 'error' => 'Role-Play is not installed on this forum.'];
        }
        $c = ArmoryCharacter::query()->where('id', $id)->where('user_id', $userId)->first();
        if (! $c) {
            return ['ok' => false, 'error' => 'Character not found.'];
        }

        $stats = $this->sheet->full($id)['stats'] ?? [];
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

        // Atomic deck rebuild across the foreign Role-Play tables: deleting the
        // old signature cards, inserting the rescaled set, and repointing
        // rp_sheets.equipped must be all-or-nothing so a mid-insert failure can't
        // leave a half-rebuilt deck. (rp_cards assumed cols: user_id, name, icon,
        // type, description, attack_expr, damage_expr, defense, hp, cost,
        // is_public, timestamps.)
        $equipped = $this->db->transaction(function () use ($userId, $cards, $ilvl, $charId) {
            $this->db->table('rp_cards')->where('user_id', $userId)
                ->whereIn('name', array_map(fn ($x) => $x['name'], $cards))->delete();

            $mod = (int) floor($ilvl / 40);
            $ids = [];
            foreach ($cards as $card) {
                $dmg = ! empty($card['damage']) ? $card['damage'].($mod > 0 ? '+'.$mod : '') : null;
                $ids[] = $this->db->table('rp_cards')->insertGetId([
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
            $this->db->table('rp_sheets')->where('character_id', $charId)->update(['equipped' => json_encode(array_slice($ids, 0, 6))]);

            return $ids;
        });

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
}
