/**
 * The Armory page renderer — framework-agnostic DOM built into a host element.
 * Ported from the Convoro version so the Flarum + Convoro armories match. The
 * Flarum page component calls mountArmory() in oncreate; data comes from the
 * extension's JSON API (public GETs; POSTs carry the Flarum CSRF token).
 */

import { esc, QUAL, buildTip, showTip, positionTip, hideTip } from './tooltip';

const CLASS_COLOR: Record<string, string> = {
  'Death Knight': '#C41E3A', 'Demon Hunter': '#A330C9', Druid: '#FF7C0A', Evoker: '#33937F',
  Hunter: '#AAD372', Mage: '#3FC7EB', Monk: '#00FF98', Paladin: '#F48CBA', Priest: '#bfbfbf',
  Rogue: '#FFF468', Shaman: '#0070DD', Warlock: '#8788EE', Warrior: '#C69B6D',
};
const cc = (k: string) => CLASS_COLOR[k] || 'inherit';
const fc = (f: string) => (/horde/i.test(f || '') ? '#b91c1c' : /alliance/i.test(f || '') ? '#1f6feb' : 'inherit');
const tz = (s: string) => (s ? s.charAt(0) + s.slice(1).toLowerCase() : s);
const getJson = (p: string) =>
  fetch('/api' + p, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    .then((r) => (r.ok ? r.json() : null))
    .catch(() => null);

export function mountArmory(root: HTMLElement, csrf: string) {
  root.innerHTML = '<aside class="ar-roster" id="ar-roster"></aside><section id="ar-detail"><div class="ar-hero"><div class="ar-empty">Loading…</div></div></section>';
  root.classList.add('ar-wrap');
  const roster = root.querySelector('#ar-roster') as HTMLElement;
  const detail = root.querySelector('#ar-detail') as HTMLElement;

  const postAction = (p: string) =>
    fetch('/api' + p, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin' })
      .then((r) => (r.ok ? r.json() : null))
      .catch(() => null);

  function empty(msg: string, extra?: string) {
    detail.innerHTML = '<div class="ar-hero"><div class="ar-empty">' + msg + (extra || '') + '</div></div>';
  }

  let D: any = null;
  let activeTab = 'gear';
  let OWN = false;
  let RP_OK = false;
  const EQ: any[] = [];
  const LEFT: [string, string[]][] = [['Head', ['head']], ['Neck', ['neck']], ['Shoulders', ['shoulder']], ['Back', ['back']], ['Chest', ['chest']], ['Shirt', ['shirt']], ['Tabard', ['tabard']], ['Wrist', ['wrist']]];
  const RIGHT: [string, string[]][] = [['Hands', ['hands']], ['Waist', ['waist']], ['Legs', ['legs']], ['Feet', ['feet']], ['Ring 1', ['ring 1', 'finger 1']], ['Ring 2', ['ring 2', 'finger 2']], ['Trinket 1', ['trinket 1']], ['Trinket 2', ['trinket 2']]];
  const WEPS: [string, string[]][] = [['Main Hand', ['main hand', 'main-hand']], ['Off Hand', ['off hand', 'off-hand']]];
  const TABS = [['gear', 'Gear'], ['stats', 'Stats'], ['talents', 'Talents'], ['pve', 'PvE'], ['prof', 'Professions'], ['pvp', 'PvP'], ['reputations', 'Reputations'], ['achievements', 'Achievements']];

  const findItem = (eq: any[], keys: string[]) => eq.find((i) => keys.some((k) => (i.slot || '').toLowerCase() === k || (i.slot || '').toLowerCase().indexOf(k) === 0));

  function slotHtml(label: string, it: any) {
    if (!it) return '';
    const q = QUAL[it.quality] || '#9aa0b8';
    const idx = EQ.push(it) - 1;
    const ench = it.enchants && it.enchants.length ? ' · <span class="en">' + esc(it.enchants[0].replace(/^Enchanted:\s*/, '')) + '</span>' : '';
    return '<div class="ar-slot" data-i="' + idx + '">' +
      (it.icon ? '<img class="ic" src="' + esc(it.icon) + '" style="border-color:' + q + '" alt="">' : '<span class="ic" style="border-color:' + q + '"></span>') +
      '<span class="meta"><span class="sl">' + esc(label) + '</span><span class="it" style="color:' + q + '">' + esc(it.name || '—') + '</span><span class="iv">' + (it.ilvl || '') + ench + '</span></span></div>';
  }
  const colHtml = (defs: [string, string[]][], eq: any[]) => defs.map((d) => slotHtml(d[0], findItem(eq, d[1]))).join('');
  const cell = (label: string, val: any) => '<div class="ar-statc"><b>' + esc(val) + '</b><span>' + esc(label) + '</span></div>';

  function attachTips() {
    detail.querySelectorAll('.ar-slot[data-i]').forEach((el) => {
      const it = EQ[+(el.getAttribute('data-i') || 0)];
      if (!it) return;
      el.addEventListener('mouseenter', () => showTip(buildTip(it)));
      el.addEventListener('mousemove', (e: any) => positionTip(e));
      el.addEventListener('mouseleave', () => hideTip());
    });
  }

  function headerHtml(c: any) {
    const guild = c.guild ? ' · &lt;' + esc(c.guild) + '&gt;' : '';
    const fac = c.faction ? ' · <span style="color:' + fc(c.faction) + '">' + esc(tz(c.faction)) + '</span>' : '';
    const title = 'Level ' + (c.level || 0) + ' ' + esc(c.race || '') + ' ' + esc(c.spec ? c.spec + ' ' : '') + esc(c.class || '') + guild + ' · ' + esc((c.realm_slug || '').replace(/-/g, ' ')) + ' (' + esc((c.region || 'us').toUpperCase()) + ')' + fac;
    const sync = OWN ? '<button type="button" id="ar-sync" class="Button Button--text ar-syncbtn">Sync</button>' : '';
    const rp = OWN && RP_OK ? '<button type="button" id="ar-rp" class="Button Button--primary ar-syncbtn"><i class="fas fa-dice-d20"></i> Add to Role-Play</button>' : '';
    return '<div class="ar-head"><div><h1 class="ar-name" style="color:' + cc(c.class) + '">' + esc(c.name) + '</h1><div class="ar-titleline">' + title + '</div></div><div class="ar-ilvl"><b>' + (c.item_level || 0) + '</b><span>Item level</span></div>' + rp + sync + '</div>';
  }
  function gearHtml(c: any, eq: any[]) {
    const weps = WEPS.map((w) => slotHtml(w[0], findItem(eq, w[1]))).join('');
    return '<div class="ar-body"><div class="ar-col r">' + colHtml(LEFT, eq) + '</div><div>' + (c.render_url ? '<div class="ar-render" role="img" aria-label="' + esc(c.name) + '" style="background-image:url(\'' + esc(c.render_url) + '\')"></div>' : '') + '</div><div class="ar-col">' + colHtml(RIGHT, eq) + '</div></div>' + (weps ? '<div class="ar-weps">' + weps + '</div>' : '');
  }
  function statsHtml(s: any) {
    if (!s) return '<div class="ar-empty">No stats available.</div>';
    let h = '<div class="ar-h3">Attributes</div><div class="ar-grid">' + (s.primary || []).map((p: any) => cell(p[0], p[1])).join('') + '</div>';
    h += '<div class="ar-h3">Secondary</div><div class="ar-grid">' + (s.secondary || []).map((p: any) => cell(p[0], p[1])).join('') + '</div>';
    if ((s.extra || []).length) h += '<div class="ar-h3">Defenses &amp; resources</div><div class="ar-grid">' + (s.extra || []).map((p: any) => cell(p[0], p[1])).join('') + '</div>';
    return h;
  }
  function talentsHtml(t: any) {
    if (!t) return '<div class="ar-empty">No talent data.</div>';
    let h = '';
    if (t.active) h += '<div class="ar-h3">Specialization</div><div style="font-size:15px;font-weight:700;margin-bottom:6px">' + esc(t.active) + '</div>';
    if (t.code) h += '<div class="ar-h3">Loadout import string</div><div class="ar-code">' + esc(t.code) + '</div>';
    if ((t.talents || []).length) h += '<div class="ar-h3">Talents (' + t.talents.length + ')</div><div class="ar-chips">' + t.talents.map((x: any) => '<span class="ar-chip">' + esc(x.name) + (x.rank > 1 ? ' ' + x.rank : '') + '</span>').join('') + '</div>';
    return h || '<div class="ar-empty">No talent data.</div>';
  }
  function pveHtml(m: any, raids: any) {
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
  function profHtml(p: any) {
    if (!p || (!(p.primary || []).length && !(p.secondary || []).length)) return '<div class="ar-empty">No professions.</div>';
    const block = (list: any[]) => (list || []).map((pr: any) => '<div class="ar-raid"><div class="rn">' + esc(pr.name) + '</div><div class="ar-modes">' + (pr.tiers || []).map((t: any) => '<span class="ar-mode">' + esc(t.name) + ' ' + t.skill + '/' + t.max + '</span>').join('') + '</div></div>').join('');
    let h = '';
    if ((p.primary || []).length) h += '<div class="ar-h3">Primary</div>' + block(p.primary);
    if ((p.secondary || []).length) h += '<div class="ar-h3">Secondary</div>' + block(p.secondary);
    return h;
  }
  function pvpHtml(p: any) {
    let h = '';
    if (p.honor_level != null) h += '<div class="ar-h3">Honor level</div><div class="ar-score">' + p.honor_level + '</div>';
    if ((p.brackets || []).length) h += '<div class="ar-h3">Rated brackets</div><div class="ar-grid">' + p.brackets.map((b: any) => '<div class="ar-statc"><b>' + b.rating + '</b><span>' + esc(b.name) + (b.won != null ? ' · ' + b.won + 'W / ' + b.lost + 'L' : '') + '</span></div>').join('') + '</div>';
    return h || '<div class="ar-empty">No PvP data.</div>';
  }
  function repHtml(list: any[]) {
    if (!list || !list.length) return '<div class="ar-empty">No reputations.</div>';
    return '<div class="ar-grid">' + list.map((x) => '<div class="ar-statc"><b style="font-size:13px">' + esc(x.faction) + '</b><span>' + esc(x.standing || '') + (x.value != null && x.max ? ' ' + x.value + '/' + x.max : '') + '</span></div>').join('') + '</div>';
  }
  function achHtml(a: any) {
    if (!a) return '<div class="ar-empty">No data.</div>';
    const cells: string[] = [];
    if (a.points != null) cells.push(cell('Achievement points', a.points));
    if (a.count != null) cells.push(cell('Achievements earned', a.count));
    if (a.mounts != null) cells.push(cell('Mounts', a.mounts));
    if (a.pets != null) cells.push(cell('Battle pets', a.pets));
    return cells.length ? '<div class="ar-grid">' + cells.join('') + '</div>' : '<div class="ar-empty">No data.</div>';
  }

  function tabContent(tab: string): string {
    if (tab === 'gear') return gearHtml(D.character, D.equipment || []);
    if (tab === 'stats') return statsHtml(D.stats);
    if (tab === 'talents') return talentsHtml(D.talents);
    if (tab === 'pve') return pveHtml(D.mythic, D.raids);
    if (tab === 'prof') return profHtml(D.professions);
    if (tab === 'pvp' || tab === 'reputations' || tab === 'achievements') {
      const k = '_' + tab;
      if (D[k] === undefined) {
        D[k] = 'loading';
        getJson('/armory/extra/' + D.character.id + '/' + tab).then((r: any) => { D[k] = r && r.ok ? r.data || false : false; if (activeTab === tab) renderHero(); });
        return '<div class="ar-empty">Loading…</div>';
      }
      if (D[k] === 'loading') return '<div class="ar-empty">Loading…</div>';
      if (!D[k]) return '<div class="ar-empty">No data available.</div>';
      if (tab === 'pvp') return pvpHtml(D[k]);
      if (tab === 'reputations') return repHtml(D[k]);
      return achHtml(D[k]);
    }
    return '';
  }

  function renderRoster(chars: any[], activeId: any) {
    roster.innerHTML = '';
    chars.forEach((ch) => {
      const b = document.createElement('button');
      b.className = 'ar-ritem' + (String(ch.id) === String(activeId) ? ' active' : '');
      b.innerHTML = (ch.avatar_url ? '<img src="' + esc(ch.avatar_url) + '" alt="">' : '') + '<span><span class="ar-rname" style="color:' + cc(ch.class) + '">' + esc(ch.name) + '</span><br><span class="ar-rmeta">' + (ch.item_level || 0) + ' ilvl · ' + esc((ch.realm_slug || '').replace(/-/g, ' ')) + '</span></span>';
      b.onclick = () => { activeTab = 'gear'; loadFull(ch.id, chars); };
      roster.appendChild(b);
    });
  }

  function renderHero() {
    const c = D.character;
    EQ.length = 0;
    let accent = cc(c.class);
    if (accent === 'inherit') accent = '#3fc7eb';
    const tabbar = '<div class="ar-tabs">' + TABS.map((t) => '<button type="button" class="ar-tab' + (t[0] === activeTab ? ' on' : '') + '" data-tab="' + t[0] + '">' + t[1] + '</button>').join('') + '</div>';
    detail.innerHTML = '<div class="ar-hero" style="--accent:' + accent + '">' + headerHtml(c) + tabbar + '<div class="ar-tabbody">' + tabContent(activeTab) + '</div></div>';
    detail.querySelectorAll('.ar-tab').forEach((b) => (b as HTMLElement).onclick = () => { activeTab = (b as HTMLElement).getAttribute('data-tab') || 'gear'; renderHero(); });
    if (activeTab === 'gear') attachTips();
    const sb = detail.querySelector('#ar-sync') as HTMLElement;
    if (sb) sb.onclick = () => { sb.textContent = 'Syncing…'; postAction('/armory/sync').then(() => { boot(); }); };
    const rb = detail.querySelector('#ar-rp') as HTMLElement;
    if (rb) rb.onclick = () => {
      const orig = rb.innerHTML;
      rb.textContent = 'Importing…';
      postAction('/armory/character/' + D.character.id + '/roleplay').then((r: any) => {
        if (r && r.ok) { rb.innerHTML = '<i class="fas fa-check"></i> Imported (' + r.cards + ' cards)'; }
        else { rb.innerHTML = orig; window.alert((r && r.error) || 'Role-Play import failed.'); }
      });
    };
  }

  function loadFull(id: any, chars?: any[]) {
    if (chars) renderRoster(chars, id);
    detail.innerHTML = '<div class="ar-hero"><div class="ar-empty">Loading…</div></div>';
    getJson('/armory/full/' + id).then((d: any) => {
      if (!d || !d.ok) { empty('Could not load this character.'); return; }
      D = d;
      renderHero();
    });
  }

  function boot() {
    const charParam = new URLSearchParams(location.search).get('char');
    if (charParam) { OWN = false; loadFull(charParam); return; }
    getJson('/armory/me').then((s: any) => {
      if (!s) { empty('Sign in and connect Battle.net to see your characters.'); return; }
      if (!s.configured) { empty('Armory is not configured yet.'); return; }
      if (!s.connected) { empty('Connect your Battle.net account to load your characters.', '<br><br><a class="Button Button--primary" href="/auth/battlenet">Connect Battle.net</a>'); return; }
      OWN = true;
      RP_OK = !!s.rp_installed;
      const chars = s.characters || [];
      if (!chars.length) { empty('No characters synced yet. Make sure they have logged in recently, then Sync.'); return; }
      const main = chars.filter((c: any) => c.is_main)[0] || chars[0];
      renderRoster(chars, main.id);
      loadFull(main.id, chars);
    });
  }

  boot();
}
