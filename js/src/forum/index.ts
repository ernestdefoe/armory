import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import LinkButton from 'flarum/common/components/LinkButton';
import IndexPage from 'flarum/forum/components/IndexPage';
import HeaderPrimary from 'flarum/forum/components/HeaderPrimary';
import SessionDropdown from 'flarum/forum/components/SessionDropdown';
import LogInButtons from 'flarum/forum/components/LogInButtons';
import LogInButton from 'flarum/forum/components/LogInButton';
import CommentPost from 'flarum/forum/components/CommentPost';
import TextEditor from 'flarum/common/components/TextEditor';
import Button from 'flarum/common/components/Button';
import ArmoryPage from './components/ArmoryPage';
import ItemSearchModal from './components/ItemSearchModal';
import { processWowItems } from './wowItems';

app.initializers.add('ernestdefoe-armory', () => {
  app.routes['armory'] = { path: '/armory', component: ArmoryPage };

  const trans = (k: string) => app.translator.trans('ernestdefoe-armory.forum.' + k);

  // A persistent Armory link in the header — visible to everyone (guests included)
  // on every page, so nobody has to type /armory by hand.
  extend(HeaderPrimary.prototype, 'items', (items: any) => {
    items.add(
      'armory',
      LinkButton.component({ icon: 'fab fa-battle-net', href: app.route('armory'), className: 'Button Button--link' }, trans('nav')),
      5
    );

    // An Arena link too, when the Arena extension (forumaker/arena) is installed.
    // Arena's page is per-user (their deck builder), so only for signed-in members.
    const user = app.session.user;
    if (user && app.routes['user.arena']) {
      items.add(
        'arena',
        LinkButton.component(
          { icon: 'fas fa-dungeon', href: app.route('user.arena', { username: user.username() }), className: 'Button Button--link' },
          trans('arena')
        ),
        4
      );
    }
  });

  // …and in the index sidebar navigation.
  extend(IndexPage.prototype, 'navItems', (items: any) => {
    items.add('armory', LinkButton.component({ icon: 'fab fa-battle-net', href: app.route('armory') }, trans('nav')), -10);
  });

  // …and in the session (avatar) dropdown, so it's reachable from every page when signed in.
  extend(SessionDropdown.prototype, 'items', (items: any) => {
    if (!app.session.user) return;
    items.add('armory', LinkButton.component({ icon: 'fab fa-battle-net', href: app.route('armory') }, trans('nav')), 80);
  });

  // "Sign in with Battle.net" social-login button on the log-in / sign-up modals.
  // Only shown once an admin has configured the Battle.net API client.
  extend(LogInButtons.prototype, 'items', (items: any) => {
    if (!app.forum.attribute('armory.configured')) return;
    items.add(
      'battlenet',
      LogInButton.component(
        { className: 'Button LogInButton--battlenet', icon: 'fab fa-battle-net', path: '/auth/battlenet' },
        trans('log_in_with_battlenet')
      )
    );
  });

  // Enhance [item=…] links in posts with the item name/quality/icon + a tooltip.
  extend(CommentPost.prototype, 'oncreate', function (this: any) {
    processWowItems(this.element);
  });
  extend(CommentPost.prototype, 'onupdate', function (this: any) {
    processWowItems(this.element);
  });

  // Composer toolbar button: search WoW items by name and insert an [item=ID] link.
  extend(TextEditor.prototype, 'toolbarItems', function (this: any, items: any) {
    if (!app.forum.attribute('armory.configured')) return;
    items.add(
      'wowItem',
      Button.component({
        icon: 'fab fa-battle-net',
        className: 'Button Button--icon',
        title: trans('item_link_button'),
        onclick: () => {
          const editor = this.attrs.composer?.editor;
          app.modal.show(ItemSearchModal, { onpick: (tag: string) => editor?.insertAtCursor(tag) });
        },
      })
    );
  });
});
