import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';

/**
 * WoW Token widget for Bespoke (registered via window.BespokeWidgetQueue).
 * Current token price in gold, the change since the previous sample, and a
 * sparkline over the rolling history the /armory/token endpoint maintains.
 */

declare const m: any;

let cache: { at: number; data: any } | null = null;
let inflight: Promise<any> | null = null;

function tokenData(): Promise<any> {
  if (cache && Date.now() - cache.at < 5 * 60 * 1000) return Promise.resolve(cache.data);
  if (inflight) return inflight;
  inflight = app
    .request<any>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/armory/token' })
    .then((r: any) => {
      cache = { at: Date.now(), data: r };
      inflight = null;
      return r;
    })
    .catch(() => {
      inflight = null;
      return null;
    });
  return inflight;
}

export default class TokenWidget extends Component<{ settings: Record<string, unknown> }> {
  data: any = null;
  loading = true;

  oninit(vnode: any) {
    super.oninit(vnode);
    tokenData().then((r) => {
      this.data = r && r.ok ? r : null;
      this.loading = false;
      m.redraw();
    });
  }

  view() {
    const s = this.attrs.settings || {};
    const t = (k: string, p?: any) => app.translator.trans('ernestdefoe-armory.forum.token.' + k, p);

    if (this.loading) return m('.Bespoke-w.ArmoryToken', m('p.Bespoke-w-empty', '…'));
    if (!this.data) {
      if (!document.body.classList.contains('bespoke-editing')) return null;
      return m('.Bespoke-w.ArmoryToken', m('p.Bespoke-w-empty', t('empty_hint')));
    }

    const d = this.data;
    const up = d.delta > 0;
    const down = d.delta < 0;

    return m('.Bespoke-w.ArmoryToken', [
      s.title ? m('h4', s.title as string) : null,
      m('.ArmoryToken-price', [
        m('span.ArmoryToken-gold', Number(d.price).toLocaleString()),
        m('span.ArmoryToken-unit', t('gold')),
        m(
          'span.ArmoryToken-delta' + (up ? '.up' : down ? '.down' : ''),
          up ? '▲ +' + d.delta.toLocaleString() : down ? '▼ ' + d.delta.toLocaleString() : '—'
        ),
      ]),
      this.sparkline(d.history || []),
      m('p.ArmoryToken-foot', t('foot')),
    ]);
  }

  sparkline(history: [number, number][]) {
    if (history.length < 2) return null;
    const values = history.map((h) => h[1]);
    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = Math.max(1, max - min);
    const w = 220;
    const h = 44;
    const pts = values
      .map((v, i) => `${((i / (values.length - 1)) * w).toFixed(1)},${(h - 4 - ((v - min) / span) * (h - 8)).toFixed(1)}`)
      .join(' ');

    return m(
      'svg.ArmoryToken-spark',
      { viewBox: `0 0 ${w} ${h}`, preserveAspectRatio: 'none', 'aria-hidden': 'true' },
      m('polyline', { points: pts, fill: 'none', stroke: 'currentColor', 'stroke-width': 2, 'stroke-linejoin': 'round' })
    );
  }
}
