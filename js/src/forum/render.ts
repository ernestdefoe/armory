/**
 * Pure HTML builders for the armory character sheet's display-only content
 * (gear slots, stat grids, talents, etc.). The ArmoryPage component renders its
 * reactive shell (roster, header buttons, tabs) as Mithril vnodes and drops
 * these leaf fragments in via m.trust — a pragmatic split that keeps the intricate,
 * static WoW item markup here without hand-converting ~15 builders to vnodes.
 */

import { esc, QUAL } from './tooltip';

// Class colors live in the shared catalog now (the admin bundle needs them
// too); re-exported so every existing `from '../render'` import keeps working.
export { CLASS_COLOR, cc } from '../common/classCatalog';
export const fc = (f: string) => (/horde/i.test(f || '') ? '#b91c1c' : /alliance/i.test(f || '') ? '#1f6feb' : 'inherit');
export const tz = (s: string) => (s ? s.charAt(0) + s.slice(1).toLowerCase() : s);

const LEFT: [string, string[]][] = [['Head', ['head']], ['Neck', ['neck']], ['Shoulders', ['shoulder']], ['Back', ['back']], ['Chest', ['chest']], ['Shirt', ['shirt']], ['Tabard', ['tabard']], ['Wrist', ['wrist']]];
const RIGHT: [string, string[]][] = [['Hands', ['hands']], ['Waist', ['waist']], ['Legs', ['legs']], ['Feet', ['feet']], ['Ring 1', ['ring 1', 'finger 1']], ['Ring 2', ['ring 2', 'finger 2']], ['Trinket 1', ['trinket 1']], ['Trinket 2', ['trinket 2']]];
const WEPS: [string, string[]][] = [['Main Hand', ['main hand', 'main-hand']], ['Off Hand', ['off hand', 'off-hand']]];

const findItem = (eq: any[], keys: string[]) => eq.find((i) => keys.some((k) => (i.slot || '').toLowerCase() === k || (i.slot || '').toLowerCase().indexOf(k) === 0));

function slotHtml(label: string, it: any, acc: any[]) {
  if (!it) return '';
  const q = QUAL[it.quality] || '#9aa0b8';
  const idx = acc.push(it) - 1;
  const ench = it.enchants && it.enchants.length ? ' · <span class="en">' + esc(it.enchants[0].replace(/^Enchanted:\s*/, '')) + '</span>' : '';
  return '<div class="ar-slot" data-i="' + idx + '">' +
    (it.icon ? '<img class="ic" src="' + esc(it.icon) + '" style="border-color:' + q + '" alt="">' : '<span class="ic" style="border-color:' + q + '"></span>') +
    '<span class="meta"><span class="sl">' + esc(label) + '</span><span class="it" style="color:' + q + '">' + esc(it.name || '—') + '</span><span class="iv">' + (it.ilvl || '') + ench + '</span></span></div>';
}
const colHtml = (defs: [string, string[]][], eq: any[], acc: any[]) => defs.map((d) => slotHtml(d[0], findItem(eq, d[1]), acc)).join('');
const cell = (label: string, val: any) => '<div class="ar-statc"><b>' + esc(val) + '</b><span>' + esc(label) + '</span></div>';

/** Gear tab. Pushes each rendered item into `acc` so the component can wire tooltips by data-i index. */
export function gearHtml(c: any, eq: any[], acc: any[]) {
  const weps = WEPS.map((w) => slotHtml(w[0], findItem(eq, w[1]), acc)).join('');
  return '<div class="ar-body"><div class="ar-col r">' + colHtml(LEFT, eq, acc) + '</div><div>' + (c.render_url ? '<div class="ar-render" role="img" aria-label="' + esc(c.name) + '" style="background-image:url(\'' + esc(c.render_url) + '\')"></div>' : '') + '</div><div class="ar-col">' + colHtml(RIGHT, eq, acc) + '</div></div>' + (weps ? '<div class="ar-weps">' + weps + '</div>' : '');
}
export function statsHtml(s: any) {
  if (!s) return '<div class="ar-empty">No stats available.</div>';
  let h = '<div class="ar-h3">Attributes</div><div class="ar-grid">' + (s.primary || []).map((p: any) => cell(p[0], p[1])).join('') + '</div>';
  h += '<div class="ar-h3">Secondary</div><div class="ar-grid">' + (s.secondary || []).map((p: any) => cell(p[0], p[1])).join('') + '</div>';
  if ((s.extra || []).length) h += '<div class="ar-h3">Defenses &amp; resources</div><div class="ar-grid">' + (s.extra || []).map((p: any) => cell(p[0], p[1])).join('') + '</div>';
  return h;
}
export function talentsHtml(t: any) {
  if (!t) return '<div class="ar-empty">No talent data.</div>';
  let h = '';
  if (t.active) h += '<div class="ar-h3">Specialization</div><div style="font-size:15px;font-weight:700;margin-bottom:6px">' + esc(t.active) + '</div>';
  if (t.code) h += '<div class="ar-h3">Loadout import string</div><div class="ar-code">' + esc(t.code) + '</div>';
  if ((t.talents || []).length) h += '<div class="ar-h3">Talents (' + t.talents.length + ')</div><div class="ar-chips">' + t.talents.map((x: any) => '<span class="ar-chip">' + esc(x.name) + (x.rank > 1 ? ' ' + x.rank : '') + '</span>').join('') + '</div>';
  return h || '<div class="ar-empty">No talent data.</div>';
}
export function pveHtml(m: any, raids: any) {
  let h = '';
  if (m) {
    h += '<div class="ar-h3">Mythic+ rating</div><div class="ar-score">' + (m.rating != null ? m.rating : '—') + '</div>';
    if ((m.runs || []).length) h += '<div class="ar-h3">Best runs this week</div><div class="ar-runs">' + m.runs.map((r: any) => '<div class="ar-run"><span>' + esc(r.dungeon) + '</span><b>+' + r.level + '</b></div>').join('') + '</div>';
  }
  if (raids && (raids.instances || []).length) {
    h += '<div class="ar-h3">Raids' + (raids.expansion ? ' · ' + esc(raids.expansion) : '') + '</div>';
    h += raids.instances.map((i: any) => '<div class="ar-raid"><div class="rn">' + esc(i.name) + '</div><div class="ar-modes">' + (i.modes || []).map((md: any) => '<span class="ar-mode">' + esc(md.diff) + ' ' + md.done + '/' + md.total + '</span>').join('') + '</div></div>').join('');
  }
  return h || '<div class="ar-empty">No PvE data.</div>';
}
export function profHtml(p: any) {
  if (!p || (!(p.primary || []).length && !(p.secondary || []).length)) return '<div class="ar-empty">No professions.</div>';
  const block = (list: any[]) => (list || []).map((pr: any) => '<div class="ar-raid"><div class="rn">' + esc(pr.name) + '</div><div class="ar-modes">' + (pr.tiers || []).map((t: any) => '<span class="ar-mode">' + esc(t.name) + ' ' + t.skill + '/' + t.max + '</span>').join('') + '</div></div>').join('');
  let h = '';
  if ((p.primary || []).length) h += '<div class="ar-h3">Primary</div>' + block(p.primary);
  if ((p.secondary || []).length) h += '<div class="ar-h3">Secondary</div>' + block(p.secondary);
  return h;
}
export function pvpHtml(p: any) {
  let h = '';
  if (p.honor_level != null) h += '<div class="ar-h3">Honor level</div><div class="ar-score">' + p.honor_level + '</div>';
  if ((p.brackets || []).length) h += '<div class="ar-h3">Rated brackets</div><div class="ar-grid">' + p.brackets.map((b: any) => '<div class="ar-statc"><b>' + b.rating + '</b><span>' + esc(b.name) + (b.won != null ? ' · ' + b.won + 'W / ' + b.lost + 'L' : '') + '</span></div>').join('') + '</div>';
  return h || '<div class="ar-empty">No PvP data.</div>';
}
export function repHtml(list: any[]) {
  if (!list || !list.length) return '<div class="ar-empty">No reputations.</div>';
  return '<div class="ar-grid">' + list.map((x) => '<div class="ar-statc"><b style="font-size:13px">' + esc(x.faction) + '</b><span>' + esc(x.standing || '') + (x.value != null && x.max ? ' ' + x.value + '/' + x.max : '') + '</span></div>').join('') + '</div>';
}
export function achHtml(a: any) {
  if (!a) return '<div class="ar-empty">No data.</div>';
  const cells: string[] = [];
  if (a.points != null) cells.push(cell('Achievement points', a.points));
  if (a.count != null) cells.push(cell('Achievements earned', a.count));
  if (a.mounts != null) cells.push(cell('Mounts', a.mounts));
  if (a.pets != null) cells.push(cell('Battle pets', a.pets));
  return cells.length ? '<div class="ar-grid">' + cells.join('') + '</div>' : '<div class="ar-empty">No data.</div>';
}

export const TABS: [string, string][] = [['gear', 'Gear'], ['stats', 'Stats'], ['talents', 'Talents'], ['pve', 'PvE'], ['prof', 'Professions'], ['pvp', 'PvP'], ['reputations', 'Reputations'], ['achievements', 'Achievements']];
