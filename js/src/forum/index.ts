import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import LinkButton from 'flarum/common/components/LinkButton';
import IndexPage from 'flarum/forum/components/IndexPage';
import ArmoryPage from './components/ArmoryPage';

app.initializers.add('ernestdefoe-armory', () => {
  app.routes['armory'] = { path: '/armory', component: ArmoryPage };

  // A link to the Armory in the index navigation.
  extend(IndexPage.prototype, 'navItems', (items: any) => {
    items.add(
      'armory',
      LinkButton.component({ icon: 'fab fa-battle-net', href: app.route('armory') }, app.translator.trans('ernestdefoe-armory.forum.nav')),
      -10
    );
  });
});
