import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import type Mithril from 'mithril';
import { cc } from '../render';

export interface ArmoryMain {
  name: string;
  realm: string | null;
  level: number;
  class: string | null;
  race: string | null;
  spec: string | null;
  itemLevel: number;
  guild: string | null;
  avatarUrl: string | null;
  renderUrl: string | null;
}

/**
 * The guild-forum character pane shown in a post's side column: the author's
 * main character render, name in class color, race/spec line, level + item
 * level, and guild tag. Data comes from the `armoryMain` user attribute —
 * no extra requests. Purely presentational; themes are expected to skin it.
 */
export default class ArmoryPostPane extends Component<{ main: ArmoryMain }> {
  view(): Mithril.Children {
    const c = this.attrs.main;
    const color = cc(c.class || '');
    const img = c.renderUrl || c.avatarUrl;
    const specLine = [c.race, [c.spec, c.class].filter(Boolean).join(' ')].filter((s) => s && String(s).trim()).join(' · ');

    return (
      <div className="ArmoryPostPane" style={color !== 'inherit' ? { '--ar-class': color } : undefined}>
        <Link href={app.route('armory')} className="ArmoryPostPane-frame" title={c.name}>
          {img ? (
            <img src={img} alt={c.name} loading="lazy" className={c.renderUrl ? 'is-render' : 'is-avatar'} />
          ) : (
            <span className="ArmoryPostPane-noimg" aria-hidden="true">
              <i className="fas fa-user-shield" />
            </span>
          )}
        </Link>
        <span className="ArmoryPostPane-name" style={color !== 'inherit' ? { color } : undefined}>
          {c.name}
        </span>
        {specLine ? <span className="ArmoryPostPane-spec">{specLine}</span> : null}
        <span className="ArmoryPostPane-ilvl">
          {app.translator.trans('ernestdefoe-armory.forum.pane_level_ilvl', { level: c.level, ilvl: c.itemLevel })}
        </span>
        {c.guild ? <span className="ArmoryPostPane-guild">{'<' + c.guild + '>'}</span> : null}
      </div>
    );
  }
}
