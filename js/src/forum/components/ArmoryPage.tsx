import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { tr, cc, fc, tz, gearHtml, statsHtml, talentsHtml, pveHtml, profHtml, pvpHtml, repHtml, achHtml, TABS } from '../render';
import { esc, buildTip, showTip, positionTip, hideTip } from '../tooltip';

/**
 * The /armory character sheet, as a Mithril component: all state lives on the
 * instance (no module globals), data flows through app.request (CSRF + error
 * handling + the Flarum pipeline), and the reactive shell — roster, header
 * actions, tabs — is Mithril vdom with redraw-driven events. The intricate,
 * display-only tab bodies come from ../render via m.trust (see that file).
 */
export default class ArmoryPage extends Page {
  loading = true;
  error: string | null = null;
  connectPrompt = false;
  chars: any[] = [];
  activeId: any = null;
  own = false;
  rpOk = false;
  arenaOk = false;
  D: any = null;
  activeTab = 'gear';
  syncing = false;
  importState: Record<string, string> = {};
  mainConfirmed = true;
  settingMain = false;
  // Vault is user-scoped (every linked character at once), unlike the other
  // character-scoped tabs — so it's fetched once and kept across roster clicks.
  vault: any = null;
  vaultState = 'idle';
  // Open character lookup (any realm/region, no Battle.net link needed).
  searchRegion = 'us';
  searchRealm = '';
  searchName = '';
  searching = false;
  searchActive = false;
  searchError: string | null = null;
  private eq: any[] = [];

  oninit(vnode: any) {
    super.oninit(vnode);
    this.boot();
  }

  private req(path: string, method: 'GET' | 'POST' = 'GET') {
    return app.request<any>({ method, url: app.forum.attribute('apiUrl') + path });
  }

  boot() {
    this.loading = true;
    this.error = null;
    this.connectPrompt = false;
    this.D = null;
    // Roster may have changed (e.g. after a Sync) — re-pull vault on next open.
    this.vault = null;
    this.vaultState = 'idle';

    const charParam = m.route.param('char');
    if (charParam) {
      this.own = false;
      this.loadFull(charParam);
      return;
    }
    if (!app.session.user) {
      this.loading = false;
      this.error = tr('sign_in_prompt');
      return;
    }

    this.req('/armory/me')
      .then((s: any) => {
        this.loading = false;
        if (!s || !s.configured) {
          this.error = tr('not_configured');
        } else if (!s.connected) {
          this.connectPrompt = true;
        } else {
          this.own = true;
          this.rpOk = !!s.rp_installed;
          this.arenaOk = !!s.arena_installed;
          this.mainConfirmed = !!s.main_confirmed;
          this.chars = s.characters || [];
          if (!this.chars.length) {
            this.error = tr('no_characters');
          } else {
            const main = this.chars.filter((c: any) => c.is_main)[0] || this.chars[0];
            this.loadFull(main.id);
            return;
          }
        }
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        this.error = tr('sign_in_prompt');
        m.redraw();
      });
  }

  loadFull(id: any) {
    this.activeId = id;
    this.activeTab = 'gear';
    this.D = null;
    this.loading = true;
    m.redraw();
    this.req('/armory/full/' + id)
      .then((d: any) => {
        this.loading = false;
        if (!d || !d.ok) this.error = tr('load_failed');
        else {
          this.error = null;
          this.D = d;
        }
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        this.error = tr('load_failed');
        m.redraw();
      });
  }

  /** Tab strip. The Vault tab is your-roster-wide, so it only shows on your own sheet. */
  tabList(): [string, string][] {
    if (!this.own) return TABS;
    // Slot Vault right after PvE — it's progression, like M+/raids.
    return [...TABS.slice(0, 4), ['vault', 'tab_vault'], ...TABS.slice(4)] as [string, string][];
  }

  doSearch(e?: Event) {
    if (e) e.preventDefault();
    const realm = this.searchRealm.trim();
    const name = this.searchName.trim();
    if (!realm || !name || this.searching) return;
    this.searching = true;
    this.searchError = null;
    m.redraw();
    const url = `/armory/search?region=${encodeURIComponent(this.searchRegion)}&realm=${encodeURIComponent(realm)}&name=${encodeURIComponent(name)}`;
    this.req(url)
      .then((r: any) => {
        this.searching = false;
        if (r && r.ok) {
          // A lookup payload has the same shape as /full, so the existing sheet
          // renders it. own=false hides Sync / imports / the Vault tab.
          this.own = false;
          this.searchActive = true;
          this.connectPrompt = false;
          this.error = null;
          this.activeTab = 'gear';
          this.D = r;
        } else if (r && r.reason === 'rate_limited') {
          this.searchError = tr('search_rate_limited');
        } else {
          this.searchError = tr('search_not_found');
        }
        m.redraw();
      })
      .catch((err: any) => {
        this.searching = false;
        this.searchError = err && err.status === 429 ? tr('search_rate_limited') : tr('search_failed');
        m.redraw();
      });
  }

  clearSearch() {
    this.searchActive = false;
    this.searchError = null;
    this.D = null;
    this.boot();
  }

  setTab(tab: string) {
    this.activeTab = tab;
    if (tab === 'vault' && this.vaultState === 'idle') {
      this.vaultState = 'loading';
      this.req('/armory/vault')
        .then((r: any) => {
          this.vault = r;
          this.vaultState = r && r.ok ? 'done' : 'error';
          m.redraw();
        })
        .catch(() => {
          this.vaultState = 'error';
          m.redraw();
        });
    }
    if (['pvp', 'reputations', 'achievements'].includes(tab) && this.D && this.D['_' + tab] === undefined) {
      this.D['_' + tab] = 'loading';
      const c = this.D.character;
      // A lookup result has no DB id — fetch its extra tabs via the open search
      // endpoint (region+realm+name) instead of the by-id one.
      const url = this.D.lookup
        ? `/armory/search?region=${encodeURIComponent(c.region)}&realm=${encodeURIComponent(c.realm_slug)}&name=${encodeURIComponent(c.name)}&kind=${tab}`
        : '/armory/extra/' + c.id + '/' + tab;
      this.req(url)
        .then((r: any) => {
          this.D['_' + tab] = r && r.ok ? r.data || false : false;
          m.redraw();
        })
        .catch(() => {
          this.D['_' + tab] = false;
          m.redraw();
        });
    }
  }

  doSync() {
    this.syncing = true;
    this.req('/armory/sync', 'POST')
      .then((r: any) => {
        // Token expired / not linked: re-authenticate with Battle.net so we can
        // re-list the account and pick up newly created characters. The callback
        // re-syncs automatically with the fresh token.
        if (r && (r.reason === 'reauth' || r.reason === 'not_linked' || r.reason === 'profile_unavailable')) {
          window.location.href = app.forum.attribute('baseUrl') + '/auth/battlenet';
          return;
        }
        // The sync now runs in the background (queued so ~60 Blizzard calls never
        // block a web worker). Poll /armory/me until the account's synced_at
        // advances past its pre-sync value, then reload the freshly synced roster.
        if (r && r.queued) {
          this.pollSync(r.since ?? null, 0);
          return;
        }
        // Legacy synchronous backend (pre-queue): reload immediately.
        if (r && r.ok) {
          this.boot();
          return;
        }
        this.syncing = false;
        m.redraw();
      })
      .catch(() => {
        this.syncing = false;
        m.redraw();
      });
  }

  private pollSync(prevSyncedAt: string | null, attempt: number) {
    // Give the background job time to finish, but never spin forever: after
    // ~45s (15 × 3s) just reload whatever is there.
    if (attempt >= 15) {
      this.syncing = false;
      this.boot();
      return;
    }
    setTimeout(() => {
      this.req('/armory/me')
        .then((s: any) => {
          if (s && s.synced_at && s.synced_at !== prevSyncedAt) {
            this.syncing = false;
            this.boot();
          } else {
            this.pollSync(prevSyncedAt, attempt + 1);
          }
        })
        .catch(() => this.pollSync(prevSyncedAt, attempt + 1));
    }, 3000);
  }

  doImport(action: string) {
    this.importState[action] = 'importing';
    this.req('/armory/character/' + this.D.character.id + '/' + action, 'POST')
      .then((r: any) => {
        this.importState[action] = r && r.ok ? 'done:' + (r.cards || 0) : 'error';
        if (!(r && r.ok)) window.alert((r && r.error) || tr('import_failed'));
        m.redraw();
      })
      .catch(() => {
        this.importState[action] = 'error';
        window.alert(tr('import_failed'));
        m.redraw();
      });
  }

  view() {
    const t = (k: string, params?: any) => app.translator.trans('ernestdefoe-armory.forum.' + k, params);

    return (
      <div className="ArmoryPage">
        <div className="container">
          {this.searchBar()}
          {this.own && this.chars.length > 0 && !this.mainConfirmed && !this.searchActive ? (
            <div className="ar-pickbanner">
              <i className="fas fa-star" aria-hidden="true" />
              <span>{t('pick_main_banner')}</span>
            </div>
          ) : null}
          <div className={'ar-wrap' + (this.searchActive || !this.own ? ' ar-wrap--full' : '')}>
            {this.searchActive || !this.own ? null : <aside className="ar-roster">{this.chars.map((ch) => this.rosterItem(ch))}</aside>}
            <section className="ar-detail">{this.detailView()}</section>
          </div>
        </div>
      </div>
    );
  }

  /** Open lookup: search any character on any realm/region. Visible to everyone. */
  searchBar() {
    const REGIONS: [string, string][] = [
      ['us', 'US'],
      ['eu', 'EU'],
      ['kr', 'KR'],
      ['tw', 'TW'],
    ];
    return (
      <form className="ar-search" onsubmit={(e: Event) => this.doSearch(e)}>
        <select
          className="FormControl ar-search-region"
          value={this.searchRegion}
          onchange={(e: any) => {
            this.searchRegion = e.target.value;
          }}
        >
          {REGIONS.map(([v, l]) => (
            <option value={v}>{l}</option>
          ))}
        </select>
        <input
          className="FormControl ar-search-realm"
          placeholder={tr('search_realm')}
          value={this.searchRealm}
          oninput={(e: any) => {
            this.searchRealm = e.target.value;
          }}
        />
        <input
          className="FormControl ar-search-name"
          placeholder={tr('search_name')}
          value={this.searchName}
          oninput={(e: any) => {
            this.searchName = e.target.value;
          }}
        />
        <button type="submit" className="Button Button--primary" disabled={this.searching}>
          {this.searching ? '…' : [<i className="fas fa-search" aria-hidden="true" />, ' ', tr('search_button')]}
        </button>
        {this.searchActive && app.session.user ? (
          <button type="button" className="Button Button--text ar-search-clear" onclick={() => this.clearSearch()}>
            <i className="fas fa-arrow-left" aria-hidden="true" /> {tr('my_characters')}
          </button>
        ) : null}
        {this.searchError ? <div className="ar-search-err">{this.searchError}</div> : null}
      </form>
    );
  }

  rosterItem(ch: any) {
    const t = (k: string) => app.translator.trans('ernestdefoe-armory.forum.' + k);

    return (
      <button type="button" className={'ar-ritem' + (String(ch.id) === String(this.activeId) ? ' active' : '')} onclick={() => this.loadFull(ch.id)}>
        {ch.avatar_url ? <img src={ch.avatar_url} alt="" /> : null}
        <span>
          <span className="ar-rname" style={{ color: cc(ch.class) }}>
            {ch.name}
          </span>
          <br />
          <span className="ar-rmeta">{tr('ilvl_short', { ilvl: ch.item_level || 0 }) + ' · ' + (ch.realm_slug || '').replace(/-/g, ' ')}</span>
        </span>
        {this.own ? (
          <span
            role="button"
            tabindex="0"
            className={'ar-star' + (ch.is_main ? ' on' : '')}
            title={String(ch.is_main ? t('primary_character') : t('make_primary'))}
            aria-label={String(ch.is_main ? t('primary_character') : t('make_primary'))}
            onclick={(e: MouseEvent) => {
              e.stopPropagation();
              if (!ch.is_main) this.setMainChar(ch.id);
            }}
          >
            <i className={(ch.is_main ? 'fas' : 'far') + ' fa-star'} aria-hidden="true" />
          </span>
        ) : null}
      </button>
    );
  }

  setMainChar(id: any) {
    if (this.settingMain) return;
    this.settingMain = true;
    this.req('/armory/character/' + id + '/main', 'POST')
      .then(() => {
        this.chars = this.chars.map((c: any) => ({ ...c, is_main: String(c.id) === String(id) }));
        this.mainConfirmed = true;
        this.settingMain = false;
        m.redraw();
      })
      .catch(() => {
        this.settingMain = false;
        m.redraw();
      });
  }

  detailView() {
    if (this.connectPrompt) {
      return (
        <div className="ar-hero">
          <div className="ar-empty">
            {tr('connect_prompt')}
            <br />
            <br />
            <a className="Button Button--primary" href="/auth/battlenet">
              {app.translator.trans('ernestdefoe-armory.forum.log_in_with_battlenet')}
            </a>
          </div>
        </div>
      );
    }
    if (this.loading && !this.D)
      return (
        <div className="ar-hero">
          <div className="ar-empty">
            <LoadingIndicator />
          </div>
        </div>
      );
    if (this.error)
      return (
        <div className="ar-hero">
          <div className="ar-empty">{this.error}</div>
        </div>
      );
    if (!this.D)
      return (
        <div className="ar-hero">
          <div className="ar-empty">
            <LoadingIndicator />
          </div>
        </div>
      );

    const c = this.D.character;
    let accent = cc(c.class);
    if (accent === 'inherit') accent = '#3fc7eb';
    return (
      <div className="ar-hero" style={`--accent:${accent}`}>
        {this.headerView(c)}
        <div className="ar-tabs">
          {this.tabList().map(([id, label]) => (
            <button type="button" className={'ar-tab' + (id === this.activeTab ? ' on' : '')} onclick={() => this.setTab(id)}>
              {tr(label)}
            </button>
          ))}
        </div>
        <div className="ar-tabbody" oncreate={(v: any) => this.wireTips(v.dom)} onupdate={(v: any) => this.wireTips(v.dom)}>
          {this.activeTab === 'vault' ? this.vaultView() : m.trust(this.tabContent())}
        </div>
      </div>
    );
  }

  headerView(c: any) {
    const guild = c.guild ? ` · <${c.guild}>` : '';
    const title = `${tr('level', { level: c.level || 0 })} ${c.race || ''} ${c.spec ? c.spec + ' ' : ''}${c.class || ''}${guild} · ${(c.realm_slug || '').replace(/-/g, ' ')} (${(c.region || 'us').toUpperCase()})`;
    return (
      <div className="ar-head">
        <div>
          <h1 className="ar-name" style={{ color: cc(c.class) }}>
            {c.name}
          </h1>
          <div className="ar-titleline">
            {title}
            {c.faction ? [' · ', <span style={{ color: fc(c.faction) }}>{tz(c.faction)}</span>] : null}
          </div>
        </div>
        <div className="ar-ilvl">
          <b>{c.item_level || 0}</b>
          <span>{tr('item_level')}</span>
        </div>
        {this.own && this.rpOk ? this.importBtn('roleplay', 'fas fa-dice-d20', tr('add_to_roleplay'), tr('imported')) : null}
        {this.own && this.arenaOk ? this.importBtn('arena', 'fas fa-dungeon', tr('add_to_arena'), tr('deck_built')) : null}
        {this.own ? (
          <button type="button" className="Button Button--text ar-syncbtn" disabled={this.syncing} onclick={() => this.doSync()}>
            {this.syncing ? tr('syncing') : tr('sync')}
          </button>
        ) : null}
      </div>
    );
  }

  importBtn(action: string, icon: string, label: string, doneVerb: string) {
    const st = this.importState[action];
    let content: any;
    if (st === 'importing') content = tr('importing');
    else if (st && st.startsWith('done:'))
      content = [<i className="fas fa-check" />, ' ' + tr('import_done', { verb: doneVerb, count: Number(st.slice(5)) })];
    else content = [<i className={icon} />, ' ' + label];
    return (
      <button type="button" className="Button Button--primary ar-syncbtn" disabled={st === 'importing'} onclick={() => this.doImport(action)}>
        {content}
      </button>
    );
  }

  /**
   * The Great Vault tab: every linked character's weekly progress at once.
   * Rendered as real vnodes (not m.trust) because it fills in asynchronously —
   * an m.trust body updated from an async redraw doesn't reliably re-diff.
   */
  vaultView(): any {
    if (this.vaultState === 'loading' || this.vaultState === 'idle') {
      return (
        <div className="ar-empty">
          <LoadingIndicator />
        </div>
      );
    }
    if (this.vaultState === 'error') {
      return <div className="ar-empty">{tr('vault_error')}</div>;
    }
    const chars = (this.vault && this.vault.characters) || [];
    return [
      this.vault && this.vault.secondsUntilReset != null ? (
        <div className="ar-vaultreset">
          {app.translator.trans('ernestdefoe-armory.forum.sheet.vault_reset', { time: <b>{this.countdown(this.vault.secondsUntilReset)}</b> })}
        </div>
      ) : null,
      chars.length === 0 ? (
        <div className="ar-empty">{tr('vault_empty')}</div>
      ) : (
        <div className="ar-vault">{chars.map((row: any) => this.vaultCard(row))}</div>
      ),
      chars.length > 0 ? <div className="ar-vaultnote">{tr('vault_note')}</div> : null,
    ];
  }

  private vaultCard(row: any): any {
    const c = row.character || {};
    const mth = row.mythic || { slots: [] };
    const rd = row.raid || { slots: [] };
    const mNote = tr('vault_dungeons', { count: mth.count }) + (mth.highest ? ' · ' + tr('vault_best', { level: mth.highest }) : '');
    const rNote = tr('vault_bosses', { count: rd.count }) + (rd.instance ? ' · ' + rd.instance : '');
    return (
      <div className="ar-vaultc">
        <div className="ar-vaulth">
          <span className="nm" style={{ color: cc(c.class) }}>
            {c.name || '?'}
          </span>
          <span className="il">{tr('ilvl_short', { ilvl: c.itemLevel || 0 })}</span>
        </div>
        <div className="ar-vrow">
          <span className="lbl">{tr('vault_mythic')}</span>
          {this.vaultSlots(mth.slots || [], 'mythic')}
          <span className="ar-vnote">{mNote}</span>
        </div>
        <div className="ar-vrow">
          <span className="lbl">{tr('vault_raid')}</span>
          {this.vaultSlots(rd.slots || [], 'raid')}
          <span className="ar-vnote">{rNote}</span>
        </div>
      </div>
    );
  }

  private vaultSlots(slots: any[], kind: 'mythic' | 'raid'): any {
    const DIFF: Record<number, string> = { 1: 'LFR', 2: 'N', 3: 'H', 4: 'M' };
    return (
      <div className="ar-vslots">
        {slots.map((s: any) => {
          let label = String(s.need);
          if (s.filled) label = kind === 'mythic' ? (s.unlockedBy != null ? '+' + s.unlockedBy : '✓') : DIFF[s.unlockedBy] || '✓';
          return (
            <span
              className={'ar-vslot' + (s.filled ? ' filled' : '')}
              title={s.filled ? tr('vault_unlocked') : tr('vault_need', { count: s.remaining })}
            >
              {label}
            </span>
          );
        })}
      </div>
    );
  }

  private countdown(secs: number): string {
    secs = Math.max(0, Math.floor(secs || 0));
    const d = Math.floor(secs / 86400);
    const h = Math.floor((secs % 86400) / 3600);
    const mnt = Math.floor((secs % 3600) / 60);
    if (d > 0) return tr('countdown_dh', { d, h });
    if (h > 0) return tr('countdown_hm', { h, m: mnt });
    return tr('countdown_m', { m: mnt });
  }

  tabContent(): string {
    const D = this.D;
    const tab = this.activeTab;
    this.eq = [];
    if (tab === 'gear') return gearHtml(D.character, D.equipment || [], this.eq);
    if (tab === 'stats') return statsHtml(D.stats);
    if (tab === 'talents') return talentsHtml(D.talents);
    if (tab === 'pve') return pveHtml(D.mythic, D.raids);
    if (tab === 'prof') return profHtml(D.professions);
    const v = D['_' + tab];
    if (v === undefined || v === 'loading') return '<div class="ar-empty">' + esc(tr('loading')) + '</div>';
    if (!v) return '<div class="ar-empty">' + esc(tr('no_data_available')) + '</div>';
    if (tab === 'pvp') return pvpHtml(v);
    if (tab === 'reputations') return repHtml(v);
    return achHtml(v);
  }

  wireTips(dom: HTMLElement) {
    if (this.activeTab !== 'gear' || !dom) return;
    dom.querySelectorAll('.ar-slot[data-i]').forEach((el) => {
      if ((el as any)._tipWired) return;
      (el as any)._tipWired = true;
      const it = this.eq[+(el.getAttribute('data-i') || 0)];
      if (!it) return;
      el.addEventListener('mouseenter', () => showTip(buildTip(it)));
      el.addEventListener('mousemove', (e) => positionTip(e as MouseEvent));
      el.addEventListener('mouseleave', () => hideTip());
    });
  }
}
