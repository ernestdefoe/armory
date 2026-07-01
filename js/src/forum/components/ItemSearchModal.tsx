import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { QUAL } from '../tooltip';

/**
 * Composer picker: search WoW items by name and insert an [item=ID] tag.
 * `attrs.onpick(tag: string)` receives the tag to insert at the cursor.
 */
export default class ItemSearchModal extends Modal {
  query = '';
  results: any[] = [];
  loading = false;
  private timer: any = null;

  className() {
    return 'ItemSearchModal Modal--small';
  }

  title() {
    return app.translator.trans('ernestdefoe-armory.forum.item_search.title');
  }

  content() {
    return (
      <div className="Modal-body">
        <div className="Form-group">
          <input
            className="FormControl"
            autofocus
            value={this.query}
            placeholder={app.translator.trans('ernestdefoe-armory.forum.item_search.placeholder')}
            oninput={(e: any) => this.oninput(e.target.value)}
          />
        </div>
        {this.loading ? <LoadingIndicator /> : this.resultsView()}
      </div>
    );
  }

  resultsView() {
    if (!this.query.trim()) {
      return <p className="helpText">{app.translator.trans('ernestdefoe-armory.forum.item_search.hint')}</p>;
    }
    if (!this.results.length) {
      return <p className="helpText">{app.translator.trans('ernestdefoe-armory.forum.item_search.none')}</p>;
    }
    return (
      <ul className="ItemSearchModal-results">
        {this.results.map((r) => (
          <li>
            <button type="button" className="Button Button--text ItemSearchModal-result" onclick={() => this.pick(r)}>
              <span style={{ color: QUAL[r.quality] || 'inherit' }}>{r.name || 'item #' + r.id}</span>
              {r.level ? <span className="ItemSearchModal-ilvl">{r.level}</span> : null}
            </button>
          </li>
        ))}
      </ul>
    );
  }

  oninput(v: string) {
    this.query = v;
    clearTimeout(this.timer);
    this.timer = setTimeout(() => this.search(), 300);
  }

  search() {
    const q = this.query.trim();
    if (!q) {
      this.results = [];
      m.redraw();
      return;
    }
    this.loading = true;
    m.redraw();
    fetch('/api/armory/item-search?q=' + encodeURIComponent(q), {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => {
        this.results = (d && d.results) || [];
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  pick(r: any) {
    this.attrs.onpick('[item=' + r.id + ']');
    this.hide();
  }
}
