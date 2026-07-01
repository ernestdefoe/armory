/**
 * Shared WoW item tooltip renderer + a single floating tooltip element.
 * Used by the Armory character page (equipped items) and by [item=…] post links.
 */

export const QUAL: Record<string, string> = {
  POOR: '#9d9d9d',
  COMMON: '#cfcfcf',
  UNCOMMON: '#1eff00',
  RARE: '#0070dd',
  EPIC: '#a335ee',
  LEGENDARY: '#ff8000',
  ARTIFACT: '#e6cc80',
  HEIRLOOM: '#00ccff',
};

export const esc = (s: any) => {
  const d = document.createElement('div');
  d.textContent = s == null ? '' : String(s);
  return d.innerHTML;
};

/** Build the inner HTML for an item tooltip from a normalized item object. */
export function buildTip(it: any): string {
  const q = QUAL[it.quality] || '#fff';
  let h = '<div class="tn" style="color:' + q + '">' + esc(it.name) + '</div>';
  if (it.ilvlStr) h += '<div class="g">' + esc(it.ilvlStr) + '</div>';
  if (it.nameDesc) h += '<div class="g">' + esc(it.nameDesc) + '</div>';
  if (it.binding) h += '<div class="w">' + esc(it.binding) + '</div>';
  if (it.invtype || it.type) h += '<div class="rowx"><span class="w">' + esc(it.invtype || '') + '</span><span class="w">' + esc(it.type || '') + '</span></div>';
  if (it.armor) h += '<div class="w">' + esc(it.armor) + '</div>';
  (it.wep || []).forEach((s: string) => (h += '<div class="w">' + esc(s) + '</div>'));
  (it.stats || []).forEach((s: string) => (h += '<div class="w">' + esc(s) + '</div>'));
  if (it.durability) h += '<div class="w">' + esc(it.durability) + '</div>';
  if (it.classes) h += '<div class="w">' + esc(it.classes) + '</div>';
  if (it.requires) h += '<div class="w">' + esc(it.requires) + '</div>';
  (it.enchants || []).forEach((e: string) => (h += '<div class="gr">' + esc(e) + '</div>'));
  (it.effects || []).forEach((e: string) => (h += '<div class="gr">' + esc(e) + '</div>'));
  if (it.set) {
    const items = it.set.items || [];
    const act = items.filter((x: any) => x.active).length;
    h += '<div class="st">' + esc(it.set.name) + ' (' + act + '/' + items.length + ')</div>';
    items.forEach((x: any) => (h += '<div class="' + (x.active ? 'w' : 'gd') + '">' + esc(x.name) + '</div>'));
    (it.set.effects || []).forEach((x: any) => (h += '<div class="' + (x.active ? 'gr' : 'gd') + '">' + esc(x.str) + '</div>'));
  }
  if (it.sell) {
    const s = it.sell;
    const coins: string[] = [];
    if (s.gold) coins.push(s.gold + 'g');
    if (s.silver) coins.push(s.silver + 's');
    if (s.copper) coins.push(s.copper + 'c');
    if (coins.length) h += '<div class="sp">' + esc(s.header || 'Sell Price:') + ' ' + coins.join(' ') + '</div>';
  }
  return h;
}

// ── The single floating tooltip element (shared across the whole page) ───────

function ensureTip(): HTMLElement {
  let tip = document.getElementById('ar-tip');
  if (!tip) {
    tip = document.createElement('div');
    tip.id = 'ar-tip';
    document.body.appendChild(tip);
  }
  return tip;
}

export function showTip(html: string) {
  const t = ensureTip();
  t.innerHTML = html;
  t.style.display = 'block';
}

export function positionTip(e: MouseEvent) {
  const t = ensureTip();
  let x = e.clientX + 16;
  let y = e.clientY + 16;
  const w = t.offsetWidth;
  const hh = t.offsetHeight;
  if (x + w > window.innerWidth - 8) x = e.clientX - w - 16;
  if (y + hh > window.innerHeight - 8) y = window.innerHeight - hh - 8;
  t.style.left = Math.max(8, x) + 'px';
  t.style.top = Math.max(8, y) + 'px';
}

export function hideTip() {
  const t = document.getElementById('ar-tip');
  if (t) t.style.display = 'none';
}
