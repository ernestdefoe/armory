/**
 * Enhances [item=…] links rendered in posts (class `.WowItemLink`) with the real
 * item name in quality color, its icon, and a Wowhead-style hover tooltip — all
 * fetched lazily from the extension's public item API and cached in memory.
 */

import app from 'flarum/forum/app';
import { QUAL, esc, buildTip, showTip, positionTip, hideTip } from './tooltip';

const cache: Record<string, Promise<any>> = {};

function fetchItem(id: string): Promise<any> {
  if (!cache[id]) {
    cache[id] = app
      .request<any>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/armory/item/' + encodeURIComponent(id) })
      .then((d: any) => (d && d.ok ? d : null))
      .catch(() => null);
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
