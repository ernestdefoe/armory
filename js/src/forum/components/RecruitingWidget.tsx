import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';

import { cc } from '../render';

/**
 * "Now Recruiting" widget for Bespoke (registered via window.BespokeWidgetQueue
 * in the forum entry — only rendered on forums that also run Bespoke).
 *
 * Renders one card per recruited class from the shared `armoryRecruiting`
 * forum attribute (admin picks the classes in the Armory settings): official
 * Blizzard class icon in a class-colored plate, class-colored name, optional
 * note ("Resto or Elemental"), and an optional Apply link that wraps the card.
 *
 * With nothing configured it renders nothing on the live site, but shows a
 * hint inside the theme editor so the widget doesn't look broken there.
 */

declare const m: any;

type RecruitEntry = { slug: string; name: string; note: string; icon: string | null };

export default class RecruitingWidget extends Component<{ settings: Record<string, unknown> }> {
  view() {
    const s = this.attrs.settings || {};
    const showNotes = s.notes !== false;
    const applyUrl = String(s.applyUrl || '').trim();
    const t = (k: string) => app.translator.trans('ernestdefoe-armory.forum.recruiting.' + k);

    let entries: RecruitEntry[] = [];
    try {
      entries = (app.forum.attribute('armoryRecruiting') as RecruitEntry[]) || [];
    } catch (e) {
      /* forum not booted */
    }

    if (!entries.length) {
      // Invisible on the live site; explanatory inside the editor.
      if (!document.body.classList.contains('bespoke-editing')) return null;
      return m('.Bespoke-w.ArmoryRecruit', m('p.Bespoke-w-empty', t('empty_hint')));
    }

    return m('.Bespoke-w.ArmoryRecruit', [
      s.title ? m('h4', s.title as string) : null,
      m(
        '.ArmoryRecruit-list',
        entries.map((c) => this.card(c, showNotes, applyUrl))
      ),
    ]);
  }

  card(c: RecruitEntry, showNotes: boolean, applyUrl: string) {
    const color = cc(c.name);
    const inner = [
      m('span.ArmoryRecruit-plate', { style: { '--ar-class': color } }, [
        c.icon
          ? m('img.ArmoryRecruit-icon', { src: c.icon, alt: c.name, loading: 'lazy' })
          : m('span.ArmoryRecruit-iconPh', c.name.charAt(0)),
      ]),
      m('span.ArmoryRecruit-meta', [
        m('span.ArmoryRecruit-name', { style: { color } }, c.name),
        showNotes && c.note ? m('span.ArmoryRecruit-note', c.note) : null,
      ]),
    ];

    if (applyUrl) {
      return m('a.ArmoryRecruit-card', { key: c.slug, href: applyUrl, style: { '--ar-class': color } }, inner);
    }
    return m('.ArmoryRecruit-card', { key: c.slug, style: { '--ar-class': color } }, inner);
  }
}
