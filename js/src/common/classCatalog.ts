/**
 * The playable-class catalog shared by BOTH bundles (forum widget + admin
 * recruiting picker): canonical slugs (matching PlayableClasses::ALL on the
 * PHP side), display names, and the official class colors.
 */
export const CLASS_CATALOG: { slug: string; name: string; color: string }[] = [
  { slug: 'warrior', name: 'Warrior', color: '#C69B6D' },
  { slug: 'paladin', name: 'Paladin', color: '#F48CBA' },
  { slug: 'hunter', name: 'Hunter', color: '#AAD372' },
  { slug: 'rogue', name: 'Rogue', color: '#FFF468' },
  { slug: 'priest', name: 'Priest', color: '#bfbfbf' },
  { slug: 'deathknight', name: 'Death Knight', color: '#C41E3A' },
  { slug: 'shaman', name: 'Shaman', color: '#0070DD' },
  { slug: 'mage', name: 'Mage', color: '#3FC7EB' },
  { slug: 'warlock', name: 'Warlock', color: '#8788EE' },
  { slug: 'monk', name: 'Monk', color: '#00FF98' },
  { slug: 'druid', name: 'Druid', color: '#FF7C0A' },
  { slug: 'demonhunter', name: 'Demon Hunter', color: '#A330C9' },
  { slug: 'evoker', name: 'Evoker', color: '#33937F' },
];

export const CLASS_COLOR: Record<string, string> = Object.fromEntries(CLASS_CATALOG.map((c) => [c.name, c.color]));

export const cc = (k: string) => CLASS_COLOR[k] || 'inherit';

/** Normalize any reasonable class spelling to a catalog slug ('' = unknown). Mirrors PlayableClasses::slugFor. */
export function classSlugFor(name: string): string {
  const slug = (name || '').toLowerCase().replace(/[^a-z]/g, '');
  return CLASS_CATALOG.some((c) => c.slug === slug) ? slug : '';
}
