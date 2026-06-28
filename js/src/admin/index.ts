import app from 'flarum/admin/app';

app.initializers.add('ernestdefoe-armory', () => {
  app.extensionData
    .for('ernestdefoe-armory')
    .registerSetting({
      setting: 'armory.client_id',
      label: app.translator.trans('ernestdefoe-armory.admin.client_id'),
      help: app.translator.trans('ernestdefoe-armory.admin.client_help'),
      type: 'text',
    })
    .registerSetting({
      setting: 'armory.client_secret',
      label: app.translator.trans('ernestdefoe-armory.admin.client_secret'),
      type: 'text',
    })
    .registerSetting({
      setting: 'armory.region',
      label: app.translator.trans('ernestdefoe-armory.admin.region'),
      type: 'select',
      options: { us: 'Americas (US)', eu: 'Europe (EU)', kr: 'Korea (KR)', tw: 'Taiwan (TW)' },
      default: 'us',
    });
});
