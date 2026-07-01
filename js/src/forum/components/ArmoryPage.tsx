import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { cc, fc, tz, gearHtml, statsHtml, talentsHtml, pveHtml, profHtml, pvpHtml, repHtml, achHtml, TABS } from '../render';
import { buildTip, showTip, positionTip, hideTip } from '../tooltip';

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
  guildMode = false;
  guildData: any = null;
  mainConfirmed = true;
  settingMain = false;
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

    const charParam = m.route.param('char');
    if (charParam) {
      this.own = false;
      this.loadFull(charParam);
      return;
    }
    if (!app.session.user) {
      this.loading = false;
      this.error = 'Sign in and connect Battle.net to see your characters.';
      return;
    }

    this.req('/armory/me')
      .then((s: any) => {
        this.loading = false;
        if (!s || !s.configured) {
          this.error = 'The Armory is not configured yet.';
        } else if (!s.connected) {
          this.connectPrompt = true;
        } else {
          this.own = true;
          this.rpOk = !!s.rp_installed;
          this.arenaOk = !!s.arena_installed;
          this.mainConfirmed = !!s.main_confirmed;
          this.chars = s.characters || [];
          if (!this.chars.length) {
            this.error = 'No characters synced yet. Make sure they have logged in recently, then Sync.';
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
        this.error = 'Sign in and connect Battle.net to see your characters.';
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
        if (!d || !d.ok) this.error = 'Could not load this character.';
        else {
          this.error = null;
          this.D = d;
        }
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        this.error = 'Could not load this character.';
        m.redraw();
      });
  }

  setTab(tab: string) {
    this.activeTab = tab;
    if (['pvp', 'reputations', 'achievements'].includes(tab) && this.D && this.D['_' + tab] === undefined) {
      this.D['_' + tab] = 'loading';
      this.req('/armory/extra/' + this.D.character.id + '/' + tab)
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
    this.req('/armory/sync', 'POST').then(() => this.boot()).catch(() => {
      this.syncing = false;
      m.redraw();
    });
  }

  doImport(action: string) {
    this.importState[action] = 'importing';
    this.req('/armory/character/' + this.D.character.id + '/' + action, 'POST')
      .then((r: any) => {
        this.importState[action] = r && r.ok ? 'done:' + (r.cards || 0) : 'error';
        if (!(r && r.ok)) window.alert((r && r.error) || 'Import failed.');
        m.redraw();
      })
      .catch(() => {
        this.importState[action] = 'error';
        window.alert('Import failed.');
        m.redraw();
      });
  }

  view() {
    const t = (k: string, params?: any) => app.translator.trans('ernestdefoe-armory.forum.' + k, params);

    return (
      <div className="ArmoryPage">
        <div className="container">
          {this.own && this.chars.length > 0 && !this.mainConfirmed ? (
            <div className="ar-pickbanner">
              <i className="fas fa-star" aria-hidden="true" />
              <span>{t('pick_main_banner')}</span>
            </div>
          ) : null}
          <div className="ar-wrap">
            <aside className="ar-roster">
              {this.chars.map((ch) => this.rosterItem(ch))}
              <button
                type="button"
                className={'ar-ritem ar-ritem--guild' + (this.guildMode ? ' active' : '')}
                onclick={() => this.toggleGuild()}
              >
                <span className="ar-gicon" aria-hidden="true">
                  <i className="fas fa-shield-halved" />
                </span>
                <span>
                  <span className="ar-rname">{this.guildMode ? t('guild_back') : t('guild_button')}</span>
                </span>
              </button>
            </aside>
            <section className="ar-detail">{this.guildMode ? this.guildView() : this.detailView()}</section>
          </div>
        </div>
      </div>
    );
  }

  toggleGuild() {
    this.guildMode = !this.guildMode;
    if (this.guildMode && this.guildData === null) {
      this.guildData = 'loading';
      this.req('/armory/guild')
        .then((r: any) => {
          this.guildData = r && r.ok ? r : false;
          m.redraw();
        })
        .catch(() => {
          this.guildData = false;
          m.redraw();
        });
    }
    m.redraw();
  }

  guildView() {
    const t = (k: string, params?: any) => app.translator.trans('ernestdefoe-armory.forum.' + k, params);
    const g = this.guildData;

    if (g === 'loading' || g === null) {
      return (
        <div className="ar-hero">
          <div className="ar-empty">
            <LoadingIndicator />
            <p>{t('guild_loading')}</p>
          </div>
        </div>
      );
    }
    if (!g) {
      return (
        <div className="ar-hero">
          <div className="ar-empty">{t('guild_unavailable')}</div>
        </div>
      );
    }

    return (
      <div className="ar-hero ar-guild">
        <header className="ar-guild-head">
          <h2>{t('guild_title', { guild: g.guild || '' })}</h2>
          <span className="ar-guild-count">{t('guild_members', { count: g.members.length })}</span>
        </header>
        <ul className="ar-guild-list">
          {g.members.map((mb: any) => (
            <li className="ar-guild-row">
              <span className="ar-guild-name" style={{ color: cc(mb.class || '') }}>
                {mb.name}
              </span>
              <span className="ar-guild-class">{mb.class || ''}</span>
              <span className="ar-guild-level">{t('guild_level_short', { level: mb.level })}</span>
              <span className={'ar-guild-rank' + (mb.rank === 0 ? ' is-gm' : '')}>
                {mb.rank === 0 ? t('guild_rank_master') : t('guild_rank', { rank: mb.rank })}
              </span>
            </li>
          ))}
        </ul>
      </div>
    );
  }

  rosterItem(ch: any) {
    const t = (k: string) => app.translator.trans('ernestdefoe-armory.forum.' + k);

    return (
      <button type="button" className={'ar-ritem' + (String(ch.id) === String(this.activeId) ? ' active' : '')} onclick={() => this.loadFull(ch.id)}>
        {ch.avatar_url ? <img src={ch.avatar_url} alt="" /> : null}
        <span>
          <span className="ar-rname" style={{ color: cc(ch.class) }}>{ch.name}</span>
          <br />
          <span className="ar-rmeta">{(ch.item_level || 0) + ' ilvl · ' + (ch.realm_slug || '').replace(/-/g, ' ')}</span>
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
            Connect your Battle.net account to load your characters.
            <br />
            <br />
            <a className="Button Button--primary" href="/auth/battlenet">Sign in with Battle.net</a>
          </div>
        </div>
      );
    }
    if (this.loading && !this.D) return <div className="ar-hero"><div className="ar-empty"><LoadingIndicator /></div></div>;
    if (this.error) return <div className="ar-hero"><div className="ar-empty">{this.error}</div></div>;
    if (!this.D) return <div className="ar-hero"><div className="ar-empty"><LoadingIndicator /></div></div>;

    const c = this.D.character;
    let accent = cc(c.class);
    if (accent === 'inherit') accent = '#3fc7eb';
    return (
      <div className="ar-hero" style={`--accent:${accent}`}>
        {this.headerView(c)}
        <div className="ar-tabs">
          {TABS.map(([id, label]) => (
            <button type="button" className={'ar-tab' + (id === this.activeTab ? ' on' : '')} onclick={() => this.setTab(id)}>{label}</button>
          ))}
        </div>
        <div className="ar-tabbody" oncreate={(v: any) => this.wireTips(v.dom)} onupdate={(v: any) => this.wireTips(v.dom)}>
          {m.trust(this.tabContent())}
        </div>
      </div>
    );
  }

  headerView(c: any) {
    const guild = c.guild ? ` · <${c.guild}>` : '';
    const title = `Level ${c.level || 0} ${c.race || ''} ${c.spec ? c.spec + ' ' : ''}${c.class || ''}${guild} · ${(c.realm_slug || '').replace(/-/g, ' ')} (${(c.region || 'us').toUpperCase()})`;
    return (
      <div className="ar-head">
        <div>
          <h1 className="ar-name" style={{ color: cc(c.class) }}>{c.name}</h1>
          <div className="ar-titleline">
            {title}
            {c.faction ? [' · ', <span style={{ color: fc(c.faction) }}>{tz(c.faction)}</span>] : null}
          </div>
        </div>
        <div className="ar-ilvl"><b>{c.item_level || 0}</b><span>Item level</span></div>
        {this.own && this.rpOk ? this.importBtn('roleplay', 'fas fa-dice-d20', 'Add to Role-Play', 'Imported') : null}
        {this.own && this.arenaOk ? this.importBtn('arena', 'fas fa-dungeon', 'Add to Arena', 'Deck built') : null}
        {this.own ? (
          <button type="button" className="Button Button--text ar-syncbtn" disabled={this.syncing} onclick={() => this.doSync()}>
            {this.syncing ? 'Syncing…' : 'Sync'}
          </button>
        ) : null}
      </div>
    );
  }

  importBtn(action: string, icon: string, label: string, doneVerb: string) {
    const st = this.importState[action];
    let content: any;
    if (st === 'importing') content = 'Importing…';
    else if (st && st.startsWith('done:')) content = [<i className="fas fa-check" />, ` ${doneVerb} (${st.slice(5)} cards)`];
    else content = [<i className={icon} />, ' ' + label];
    return (
      <button type="button" className="Button Button--primary ar-syncbtn" disabled={st === 'importing'} onclick={() => this.doImport(action)}>
        {content}
      </button>
    );
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
    if (v === undefined || v === 'loading') return '<div class="ar-empty">Loading…</div>';
    if (!v) return '<div class="ar-empty">No data available.</div>';
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
