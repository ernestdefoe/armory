import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';

import RecruitingPicker from './components/RecruitingPicker';

// Mithril is global in Flarum; declared for type-checking only.
declare const m: any;

// Flarum 2 registers admin settings declaratively via the Admin extender —
// `app.extensionData.for()` (the Flarum 1 way) no longer exists.
export const extend = [
  new Extend.Admin()
    .setting(() => ({
      setting: 'armory.client_id',
      type: 'text',
      label: app.translator.trans('ernestdefoe-armory.admin.client_id'),
      help: app.translator.trans('ernestdefoe-armory.admin.client_help'),
    }))
    .setting(() => ({
      setting: 'armory.client_secret',
      type: 'text',
      label: app.translator.trans('ernestdefoe-armory.admin.client_secret'),
    }))
    .setting(() => ({
      setting: 'armory.region',
      type: 'select',
      options: { us: 'Americas (US)', eu: 'Europe (EU)', kr: 'Korea (KR)', tw: 'Taiwan (TW)' },
      default: 'us',
      label: app.translator.trans('ernestdefoe-armory.admin.region'),
    }))
    .setting(() => ({
      setting: 'armory.guild_realm',
      type: 'text',
      label: app.translator.trans('ernestdefoe-armory.admin.guild_realm'),
      help: app.translator.trans('ernestdefoe-armory.admin.guild_help'),
    }))
    .setting(() => ({
      setting: 'armory.guild_name',
      type: 'text',
      label: app.translator.trans('ernestdefoe-armory.admin.guild_name'),
    }))
    .setting(() => ({
      setting: 'armory.bnet_only',
      type: 'boolean',
      label: app.translator.trans('ernestdefoe-armory.admin.bnet_only_label'),
      help: app.translator.trans('ernestdefoe-armory.admin.bnet_only_help'),
    }))
    // Custom control: checkbox per class with the official Blizzard icon +
    // per-class note. customSetting (NOT setting — that one is invoked at boot
    // expecting a descriptor) defers the call to the page render, where `this`
    // is the admin page and this.setting() yields the bidirectional stream
    // with the page's own dirty tracking.
    .customSetting(function (this: any) {
      return m(RecruitingPicker, { stream: this.setting('armory.recruiting') });
    }),
];
