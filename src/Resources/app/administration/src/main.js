import './module/nova-versandkosten';
import './module/nova-vk-tour';
import './cms/nova-vk-tour-notify';
import './extension/sw-order-detail-details';
import './extension/sw-system-config';

import deDE from './module/nova-versandkosten/snippet/de-DE.json';
import enGB from './module/nova-versandkosten/snippet/en-GB.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
