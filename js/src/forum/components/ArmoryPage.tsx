import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import { mountArmory } from '../armory';

/** The /armory page — a thin Flarum page that mounts the framework-agnostic renderer. */
export default class ArmoryPage extends Page {
  view() {
    return (
      <div className="ArmoryPage">
        <div className="container">
          <div id="armory-root" />
        </div>
      </div>
    );
  }

  oncreate(vnode: any) {
    super.oncreate(vnode);
    const el = (vnode.dom as HTMLElement).querySelector('#armory-root') as HTMLElement;
    if (el) mountArmory(el, (app.session as any).csrfToken || '');
  }
}
