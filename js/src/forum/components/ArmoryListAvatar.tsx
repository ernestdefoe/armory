import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import Tooltip from 'flarum/common/components/Tooltip';
import humanTime from 'flarum/common/helpers/humanTime';
import type Mithril from 'mithril';
import { cc } from '../render';

/**
 * Drop-in replacement for the discussion list's author avatar: the author's
 * main character bust (Blizzard media) in a class-colored frame. Keeps the
 * exact Tooltip + profile-link semantics of the core item it replaces.
 */
export default class ArmoryListAvatar extends Component<{ user: any; main: any; discussion: any }> {
  view(): Mithril.Children {
    const { user, main, discussion } = this.attrs;
    const color = cc(main.class || '');
    const img = main.avatarUrl || main.renderUrl;

    return (
      <Tooltip
        text={app.translator.trans('core.forum.discussion_list.started_text', { user, ago: humanTime(discussion.createdAt()) })}
        position="right"
      >
        <Link
          className="DiscussionListItem-author-avatar ArmoryListAvatar"
          href={app.route.user(user)}
          style={color !== 'inherit' ? { '--ar-class': color } : undefined}
        >
          <img className="ArmoryListAvatar-img" src={img} alt={main.name} loading="lazy" />
        </Link>
      </Tooltip>
    );
  }
}
