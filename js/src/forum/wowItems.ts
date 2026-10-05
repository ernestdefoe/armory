/**
 * Enhances [item=…] links rendered in posts (class `.WowItemLink`) with the real
 * item name in quality color, its icon, and a Wowhead-style hover tooltip — all
 * fetched lazily from the extension's public item API and cached in memory.
 */

import app from 'flarum/forum/app';
import { QUAL, esc, buildTip, showTip, positionTip, hideTip } from './tooltip';

const cache: Record<string, Promise<any>> = {};

/*
 * 🚨 Batched, never one request per link.
 *
 * Every item link on the page asks during the same render pass; those asks are
 * collected and sent together on the next tick, ten ids per request (the
 * server's cap). A post listing twenty items used to fire twenty requests at
 * once — the fan-out that takes a shared host's database connections down.
 */
const BATCH = 10;
let queued: Record<string, (card: any) => void> = {};
let scheduled = false;

function flush() {
  scheduled = false;
  const pending = queued;
  queued = {};
  const ids = Object.keys(pending);

  for (let i = 0; i < ids.length; i += BATCH) {
    const chunk = ids.slice(i, i + BATCH);
    app
      .request<any>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/armory/items?ids=' + chunk.join(',') })
      .then((r: any) => {
        const data = (r && r.data) || {};
        chunk.forEach((id) => {
          const d = data[id];
          pending[id](d && d.ok ? d : null);
        });
      })
      .catch(() => chunk.forEach((id) => pending[id](null)));
  }
}

function fetchItem(id: string): Promise<any> {
  if (!cache[id]) {
    cache[id] = new Promise((resolve) => {
      queued[id] = resolve;
      if (!scheduled) {
        scheduled = true;
        setTimeout(flush, 0);
      }
    });
  }
  return cache[id];
}

/** Enhance every unprocessed WoW item link inside `root`. Idempotent. */
export function processWowItems(root: HTMLElement | null | undefined) {
  if (!root) return;
  root.querySelectorAll<HTMLAnchorElement>('a.WowItemLink[data-wow-item]').forEach((el) => {
    if (el.dataset.wowDone) return;
    el.dataset.wowDone = '1';
    const id = el.getAttribute('data-wow-item') || '';

    let card: any = null;
    el.addEventListener('mouseenter', () => {
      if (card) showTip(buildTip(card));
    });
    el.addEventListener('mousemove', (e) => {
      if (card) positionTip(e);
    });
    el.addEventListener('mouseleave', () => hideTip());

    fetchItem(id).then((c) => {
      if (!c) return;
      card = c;
      const q = QUAL[c.quality] || '';
      if (q) el.style.color = q;
      el.innerHTML =
        (c.icon ? '<img class="WowItemLink-icon" src="' + esc(c.icon) + '" alt="">' : '') +
        esc(c.name || 'item #' + id);
    });
  });
}
