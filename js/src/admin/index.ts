import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';

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
    })),
];
