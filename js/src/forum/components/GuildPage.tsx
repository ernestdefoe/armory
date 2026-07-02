import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { cc, fc, tz, gearHtml, statsHtml, talentsHtml, pveHtml, profHtml, pvpHtml, repHtml, achHtml, TABS } from '../render';
import { buildTip, showTip, positionTip, hideTip } from '../tooltip';

/**
 * The standalone /guild page: the full guild roster (every member, straight
 * from Battle.net — no forum account needed) with a click-through to any
 * member's complete character sheet, rendered with the same tab machinery as
 * the armory. Deep-linkable: /guild/:realm/:name selects a member.
 */
export default class GuildPage extends Page {
  loading = true;
  roster: any = null;
  selected: { realm: string; name: string } | null = null;
  D: any = null;
  detailLoading = false;
  detailError = false;
  activeTab = 'gear';
  private eq: any[] = [];

  oninit(vnode: any) {
    super.oninit(vnode);
    app.setTitle(String(this.t('guild_page_title')));

    this.req('/armory/guild')
      .then((r: any) => {
        this.roster = r && r.ok ? r : false;
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.roster = false;
        this.loading = false;
        m.redraw();
      });

    const realm = m.route.param('realm');
    const name = m.route.param('name');
    if (realm && name) {
      this.loadMember(String(realm), decodeURIComponent(String(name)), false);
    }
  }

  t(key: string, params?: any) {
    return app.translator.trans('ernestdefoe-armory.forum.' + key, params);
  }

  private req(path: string) {
    return app.request<any>({ method: 'GET', url: app.forum.attribute('apiUrl') + path });
  }

  loadMember(realm: string, name: string, pushRoute = true) {
    this.selected = { realm, name };
    this.D = null;
    this.detailError = false;
    this.detailLoading = true;
    this.activeTab = 'gear';
    if (pushRoute) {
      m.route.set('/guild/' + encodeURIComponent(realm) + '/' + encodeURIComponent(name));
    }
    this.req('/armory/lookup/' + encodeURIComponent(realm) + '/' + encodeURIComponent(name))
      .then((d: any) => {
        this.detailLoading = false;
        if (d && d.ok) this.D = d;
        else this.detailError = true;
        m.redraw();
      })
      .catch(() => {
        this.detailLoading = false;
        this.detailError = true;
        m.redraw();
      });
  }

  setTab(tab: string) {
    this.activeTab = tab;
    if (['pvp', 'reputations', 'achievements'].includes(tab) && this.D && this.selected && this.D['_' + tab] === undefined) {
      this.D['_' + tab] = 'loading';
      this.req(
        '/armory/lookup-extra/' + encodeURIComponent(this.selected.realm) + '/' + encodeURIComponent(this.selected.name) + '/' + tab
      )
        .then((r: any) => {
          this.D['_' + tab] = r && r.ok ? r.data || false : false;
          m.redraw();
        })
        .catch(() => {
          this.D['_' + tab] = false;
          m.redraw();
        });
    }
    m.redraw();
  }

  view() {
    return (
      <div className="GuildPage ArmoryPage">
        <div className="container">
          <div className={'ar-wrap' + (this.selected ? ' gp-has-detail' : '')}>
            <aside className="ar-roster gp-roster">{this.rosterView()}</aside>
            <section className="ar-detail">{this.detailView()}</section>
          </div>
        </div>
      </div>
    );
  }

  rosterView() {
    if (this.loading) return <div className="gp-loading"><LoadingIndicator /></div>;
    if (!this.roster) return <div className="ar-empty">{this.t('guild_unavailable')}</div>;

    return [
      <header className="gp-head">
        <h2>{this.roster.guild}</h2>
        <span className="ar-guild-count">{this.t('guild_members', { count: this.roster.members.length })}</span>
      </header>,
      <ul className="ar-guild-list gp-list">
        {this.roster.members.map((mb: any) => {
          const active = this.selected && mb.name === this.selected.name && mb.realm === this.selected.realm;
          return (
            <li>
              <button type="button" className={'ar-guild-row gp-row' + (active ? ' on' : '')} onclick={() => this.loadMember(mb.realm, mb.name)}>
                <span className="ar-guild-name" style={{ color: cc(mb.class || '') }}>{mb.name}</span>
                <span className="ar-guild-class">{mb.class || ''}</span>
                <span className="ar-guild-level">{this.t('guild_level_short', { level: mb.level })}</span>
                <span className={'ar-guild-rank' + (mb.rank === 0 ? ' is-gm' : '')}>
                  {mb.rank === 0 ? this.t('guild_rank_master') : this.t('guild_rank', { rank: mb.rank })}
                </span>
              </button>
            </li>
          );
        })}
      </ul>,
    ];
  }

  detailView() {
    if (!this.selected) {
      return (
        <div className="ar-hero gp-placeholder">
          <div className="ar-empty">
            <i className="fas fa-shield-halved" aria-hidden="true" />
            <p>{this.t('guild_pick_member')}</p>
          </div>
        </div>
      );
    }
    if (this.detailLoading) return <div className="ar-hero"><div className="ar-empty"><LoadingIndicator /></div></div>;
    if (this.detailError || !this.D) return <div className="ar-hero"><div className="ar-empty">{this.t('guild_member_error')}</div></div>;

    const c = this.D.character;
    let accent = cc(c.class);
    if (accent === 'inherit') accent = '#3fc7eb';

    return (
      <div className="ar-hero" style={`--accent:${accent}`}>
        <div className="ar-head">
          <button type="button" className="Button Button--icon gp-back" aria-label={String(this.t('guild_back_to_roster'))} onclick={() => this.clearMember()}>
            <i className="fas fa-arrow-left" aria-hidden="true" />
          </button>
          <div>
            <h1 className="ar-name" style={{ color: cc(c.class) }}>{c.name}</h1>
            <div className="ar-titleline">
              {`Level ${c.level || 0} ${c.race || ''} ${c.spec ? c.spec + ' ' : ''}${c.class || ''}${c.guild ? ' · <' + c.guild + '>' : ''} · ${(c.realm_slug || '').replace(/-/g, ' ')} (${(c.region || 'us').toUpperCase()})`}
              {c.faction ? [' · ', <span style={{ color: fc(c.faction) }}>{tz(c.faction)}</span>] : null}
            </div>
          </div>
          <div className="ar-ilvl"><b>{c.item_level || 0}</b><span>Item level</span></div>
        </div>
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

  clearMember() {
    this.selected = null;
    this.D = null;
    this.detailError = false;
    m.route.set('/guild');
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
