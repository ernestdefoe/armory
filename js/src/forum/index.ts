import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import LinkButton from 'flarum/common/components/LinkButton';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LogInButtons from 'flarum/forum/components/LogInButtons';
import LogInButton from 'flarum/forum/components/LogInButton';
import CommentPost from 'flarum/forum/components/CommentPost';
import TextEditor from 'flarum/common/components/TextEditor';
import Button from 'flarum/common/components/Button';
import ArmoryPage from './components/ArmoryPage';
import ArmoryPostPane from './components/ArmoryPostPane';
import ItemSearchModal from './components/ItemSearchModal';
import { processWowItems } from './wowItems';

app.initializers.add('ernestdefoe-armory', () => {
  app.routes['armory'] = { path: '/armory', component: ArmoryPage };

  const trans = (k: string) => app.translator.trans('ernestdefoe-armory.forum.' + k);

  // Links in the main forum navigation (the index sidebar), where nav links live.
  // In Flarum 2 the sidebar nav is IndexSidebar (NOT IndexPage.navItems).
  extend(IndexSidebar.prototype, 'navItems', (items: any) => {
    items.add('armory', LinkButton.component({ icon: 'fab fa-battle-net', href: app.route('armory') }, trans('nav')), -10);

    // An Arena link too, when the Arena extension (forumaker/arena) is installed.
    // Arena's page is per-user (their deck builder), so only for signed-in members.
    const user = app.session.user;
    if (user && app.routes['user.arena']) {
      items.add(
        'arena',
        LinkButton.component({ icon: 'fas fa-dungeon', href: app.route('user.arena', { username: user.username() }) }, trans('arena')),
        -11
      );
    }
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

  // The author's character pane in each post's side column (below the avatar).
  // Data rides in on the serialized user (`armoryMain`) — zero extra requests.
  extend(CommentPost.prototype, 'sideItems', function (this: any, items: any) {
    const user = this.attrs.post?.user?.();
    const main = user && typeof user.attribute === 'function' ? user.attribute('armoryMain') : null;
    if (main && main.name) {
      items.add('armory', ArmoryPostPane.component({ main }), 90);
    }
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
