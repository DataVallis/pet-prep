/** Typed translation keys (M1-18): `t('auth:login.title')` is checked against English. */
import 'i18next';

import type { Resources } from './resources';

declare module 'i18next' {
  interface CustomTypeOptions {
    defaultNS: 'common';
    resources: Resources;
    returnNull: false;
  }
}
