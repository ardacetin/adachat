import 'i18next';
import type auth from './locales/en/auth.json';
import type common from './locales/en/common.json';
import type settings from './locales/en/settings.json';

declare module 'i18next' {
    interface CustomTypeOptions {
        defaultNS: 'common';
        resources: {
            common: typeof common;
            auth: typeof auth;
            settings: typeof settings;
        };
    }
}
