import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import { cc } from '../render';

/**
 * /crafting — the guild crafting directory. A profession overview (who has
 * what, at what skill) plus a recipe search across every linked character's
 * known recipes. Crafter chips click through to the member's sheet on
 * /guild; usernames link to the member profile (where a DM can start).
 */
export default class CraftingPage extends Page {
  loading = true;
  professions: any[] = [];
  query = '';
  results: any[] | null = null;
  searching = false;
  private searchTimer: any = null;

  oninit(vnode: any) {
    super.oninit(vnode);
    app.setTitle(String(this.t('crafting_title')));

    app
      .request<any>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/armory/crafting' })
      .then((r: any) => {
        this.professions = r && r.ok && Array.isArray(r.professions) ? r.professions : [];
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.professions = [];
        this.loading = false;
        m.redraw();
      });
  }

  t(key: string, params?: any) {
    return app.translator.trans('ernestdefoe-armory.forum.' + key, params);
  }

  onQuery(value: string) {
    this.query = value;
    clearTimeout(this.searchTimer);
    const q = value.trim();
    if (q.length < 2) {
      this.results = null;
      this.searching = false;
      m.redraw();
      return;
    }
    this.searching = true;
    this.searchTimer = setTimeout(() => {
      app
        .request<any>({
          method: 'GET',
          url: app.forum.attribute('apiUrl') + '/armory/crafting/search',
          params: { q },
        })
        .then((r: any) => {
          if (this.query.trim() !== q) return;
          this.results = r && r.ok && Array.isArray(r.results) ? r.results : [];
          this.searching = false;
          m.redraw();
        })
        .catch(() => {
          this.results = [];
          this.searching = false;
          m.redraw();
        });
    }, 400);
  }

  view() {
    return (
      <div className="CraftingPage ArmoryPage">
        <div className="container">
          <header className="craft-head">
            <h2>
              <i className="fas fa-hammer" aria-hidden="true" /> {this.t('crafting_title')}
            </h2>
            <p className="craft-sub">{this.t('crafting_sub')}</p>
            <input
              className="FormControl craft-search"
              type="search"
              placeholder={String(this.t('crafting_search_placeholder'))}
              value={this.query}
              oninput={(e: any) => this.onQuery(e.target.value)}
            />
          </header>
          {this.body()}
        </div>
      </div>
    );
  }

  body() {
    if (this.loading) return <LoadingIndicator />;

    if (this.query.trim().length >= 2) return this.searchView();

    if (!this.professions.length) {
      return <div className="ar-empty craft-empty">{this.t('crafting_empty')}</div>;
    }

    return (
      <div className="craft-grid">
        {this.professions.map((p: any) => (
          <section className="craft-card">
            <h3>{p.profession}</h3>
            <ul>
              {p.crafters.map((c: any) => (
                <li>
                  <Link className="craft-crafter" href={'/guild/' + encodeURIComponent(c.realm) + '/' + encodeURIComponent(c.name)}>
                    <b style={{ color: cc(c.class || '') }}>{c.name}</b>
                    <span className="craft-skill">
                      {c.skill}/{c.maxSkill}
                      {c.tier ? ' · ' + c.tier : ''}
                    </span>
                  </Link>
                  <Link className="craft-member" href={app.route('user', { username: c.username })}>
                    {c.username}
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        ))}
      </div>
    );
  }

  searchView() {
    if (this.searching && this.results === null) return <LoadingIndicator />;
    if (!this.results || !this.results.length) {
      return <div className="ar-empty craft-empty">{this.t('crafting_no_results', { query: this.query.trim() })}</div>;
    }

    return (
      <div className="craft-results">
        {this.results.map((r: any) => (
          <section className="craft-recipe">
            <h4>{r.recipe}</h4>
            <div className="craft-chips">
              {r.crafters.map((c: any) => (
                <Link className="craft-chip" href={'/guild/' + encodeURIComponent(c.realm) + '/' + encodeURIComponent(c.name)}>
                  <b style={{ color: cc(c.class || '') }}>{c.name}</b>
                  <small>
                    {c.profession}
                    {c.tier ? ' · ' + c.tier : ''} · {c.username}
                  </small>
                </Link>
              ))}
            </div>
          </section>
        ))}
      </div>
    );
  }
}
