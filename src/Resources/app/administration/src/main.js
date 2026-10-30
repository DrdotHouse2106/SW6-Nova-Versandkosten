import './module/nova-versandkosten';
import './extension/sw-order-detail-details';

import deDE from './module/nova-versandkosten/snippet/de-DE.json';
import enGB from './module/nova-versandkosten/snippet/en-GB.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
