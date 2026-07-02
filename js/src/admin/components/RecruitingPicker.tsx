import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import type Mithril from 'mithril';

import { CLASS_CATALOG, classSlugFor } from '../../common/classCatalog';

/**
 * Checkbox-per-class recruiting picker for the Armory admin page, with each
 * class's official Blizzard icon and color. Reads/writes the SAME
 * `armory.recruiting` line format the server parses ("slug: note"), through
 * the admin page's bidirectional setting stream — so the page's own dirty
 * tracking and Save button handle persistence, and any legacy hand-typed
 * value ("Death Knight: Blood") is understood and rewritten canonically.
 *
 * Icons come from GET /api/armory/classes (cached server-side); rows render
 * instantly with colored placeholders and the icons pop in when they arrive.
 */

interface Attrs {
  stream: (value?: string) => string;
}

export default class RecruitingPicker extends Component<Attrs> {
  icons: Record<string, string | null> = {};

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);

    app
      .request<{ data: { slug: string; icon: string | null }[] }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/armory/classes',
      })
      .then((res) => {
        ((res as any)?.data || []).forEach((c: any) => {
          this.icons[c.slug] = c.icon;
        });
        m.redraw();
      })
      .catch(() => {
        /* icons stay as colored placeholders */
      });
  }

  /** Current selection from the setting value: slug → note (spaces preserved while typing). */
  parse(): Map<string, string> {
    const out = new Map<string, string>();
    String(this.attrs.stream() || '')
      .split(/\r\n|\r|\n/)
      .forEach((line) => {
        const t = line.trim();
        if (!t) return;
        const i = t.indexOf(':');
        const cls = i >= 0 ? t.slice(0, i) : t;
        let note = i >= 0 ? t.slice(i + 1) : '';
        if (note.startsWith(' ')) note = note.slice(1); // the "slug: note" joiner space
        const slug = classSlugFor(cls);
        if (slug && !out.has(slug)) out.set(slug, note);
      });
    return out;
  }

  write(map: Map<string, string>): void {
    const lines = CLASS_CATALOG.filter((c) => map.has(c.slug)).map((c) => {
      const note = map.get(c.slug) || '';
      return note ? `${c.slug}: ${note}` : c.slug;
    });
    this.attrs.stream(lines.join('\n'));
  }

  view() {
    const t = (k: string) => app.translator.trans('ernestdefoe-armory.admin.' + k);
    const selected = this.parse();

    return (
      <div className="Form-group ArmoryRecruitPicker">
        <label>{t('recruiting_label')}</label>
        <p className="helpText">{t('recruiting_help')}</p>
        <div className="ArmoryRecruitPicker-grid">
          {CLASS_CATALOG.map((c) => {
            const checked = selected.has(c.slug);
            const icon = this.icons[c.slug];
            return (
              <div key={c.slug} className={'ArmoryRecruitPicker-row' + (checked ? ' on' : '')} style={{ '--ar-class': c.color }}>
                <label className="ArmoryRecruitPicker-main">
                  <input
                    type="checkbox"
                    checked={checked}
                    onchange={(e: Event) => {
                      const map = this.parse();
                      if ((e.target as HTMLInputElement).checked) map.set(c.slug, map.get(c.slug) || '');
                      else map.delete(c.slug);
                      this.write(map);
                    }}
                  />
                  <span className="ArmoryRecruitPicker-plate">
                    {icon ? <img src={icon} alt="" loading="lazy" /> : <span className="ArmoryRecruitPicker-ph">{c.name.charAt(0)}</span>}
                  </span>
                  <span className="ArmoryRecruitPicker-name" style={{ color: c.color }}>
                    {c.name}
                  </span>
                </label>
                {checked && (
                  <input
                    className="FormControl ArmoryRecruitPicker-note"
                    type="text"
                    placeholder={t('recruiting_note_placeholder') as string}
                    value={selected.get(c.slug) || ''}
                    oninput={(e: Event) => {
                      const map = this.parse();
                      map.set(c.slug, (e.target as HTMLInputElement).value);
                      this.write(map);
                    }}
                  />
                )}
              </div>
            );
          })}
        </div>
      </div>
    );
  }
}
