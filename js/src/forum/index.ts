import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Application from 'flarum/common/Application';
import LinkButton from 'flarum/common/components/LinkButton';
import HeaderSecondary from 'flarum/forum/components/HeaderSecondary';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LogInButtons from 'flarum/forum/components/LogInButtons';
import LogInButton from 'flarum/forum/components/LogInButton';
import CommentPost from 'flarum/forum/components/CommentPost';
import DiscussionListItem from 'flarum/forum/components/DiscussionListItem';
import TextEditor from 'flarum/common/components/TextEditor';
import Button from 'flarum/common/components/Button';
import ArmoryPage from './components/ArmoryPage';
import ArmoryListAvatar from './components/ArmoryListAvatar';
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

  // Battle.net-only mode: hide the regular Sign Up affordances. The server
  // enforces this too (RequireBattlenetSignUp) — this is just the UI half.
  extend(HeaderSecondary.prototype, 'items', (items: any) => {
    if (app.forum.attribute('armory.bnetOnly') && app.forum.attribute('armory.configured') && items.has('signUp')) {
      items.remove('signUp');
    }
  });

  // Root-level marker so CSS can hide the log-in modal's "Sign Up" footer
  // link, plus the one-time "choose your primary character" onboarding nudge.
  // Attribute reads live in mount — app.forum is not populated during init.
  extend(Application.prototype, 'mount', function () {
    if (app.forum.attribute('armory.bnetOnly') && app.forum.attribute('armory.configured')) {
      document.documentElement.classList.add('armory-bnet-only');
    }

    if (app.session.user && app.forum.attribute('armoryNeedsMain')) {
      app.alerts.show(
        {
          type: 'success',
          dismissible: true,
          controls: [
            LinkButton.component({ href: app.route('armory'), icon: 'fas fa-star' }, trans('needs_main_cta')),
          ],
        },
        trans('needs_main_alert')
      );
    }
  });

  // The author's character pane in each post's side column. It REPLACES the
  // avatar there (the render is the identity); group badges move up beside
  // the username via the Post--armory-pane class + CSS. Data rides in on the
  // serialized user (`armoryMain`) — zero extra requests.
  const paneMain = (post: any) => {
    const user = post?.user?.();
    const main = user && typeof user.attribute === 'function' ? user.attribute('armoryMain') : null;
    return main && main.name ? main : null;
  };

  extend(CommentPost.prototype, 'sideItems', function (this: any, items: any) {
    const main = paneMain(this.attrs.post);
    if (main) {
      items.add('armory', ArmoryPostPane.component({ main }), 90);
      if (items.has('avatar')) items.remove('avatar');
    }
  });

  extend(CommentPost.prototype, 'classes', function (this: any, classes: string[]) {
    if (paneMain(this.attrs.post)) classes.push('Post--armory-pane');
  });

  // Discussion list: the author avatar becomes the main character's bust in a
  // class-colored frame (same tooltip + profile link as the core item). Users
  // without a linked character keep the regular avatar.
  extend(DiscussionListItem.prototype, 'authorItems', function (this: any, items: any) {
    const user = this.attrs.author || this.attrs.discussion?.user?.();
    const main = user && typeof user.attribute === 'function' ? user.attribute('armoryMain') : null;
    if (!user || !main || !(main.avatarUrl || main.renderUrl) || !items.has('avatar')) return;

    items.remove('avatar');
    items.add('avatar', ArmoryListAvatar.component({ user, main, discussion: this.attrs.discussion }), 100);
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
