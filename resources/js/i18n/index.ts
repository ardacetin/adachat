import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

export const namespaces = ['common', 'auth', 'settings'] as const;

/** Pass to useTranslation() when using "namespace:key" keys. */
export const allNamespaces = [...namespaces] as ['common', 'auth', 'settings'];
export const defaultNamespace = 'common';
export const fallbackLocale = 'en';

type Bundle = { default: Record<string, unknown> };

// Each locale/namespace file becomes its own chunk, loaded on demand.
const bundles = import.meta.glob<Bundle>('./locales/*/*.json');

async function loadLocale(locale: string): Promise<void> {
    await Promise.all(
        namespaces.map(async (namespace) => {
            if (i18n.hasResourceBundle(locale, namespace)) {
                return;
            }

            const loader = bundles[`./locales/${locale}/${namespace}.json`];

            if (!loader) {
                return;
            }

            const bundle = await loader();
            i18n.addResourceBundle(locale, namespace, bundle.default);
        }),
    );
}

export async function initI18n(locale: string): Promise<void> {
    await i18n.use(initReactI18next).init({
        lng: locale,
        fallbackLng: fallbackLocale,
        ns: [...namespaces],
        defaultNS: defaultNamespace,
        resources: {},
        // React already escapes rendered values.
        interpolation: { escapeValue: false },
        returnNull: false,
    });

    await loadLocale(locale);

    if (locale !== fallbackLocale) {
        await loadLocale(fallbackLocale);
    }
}

export async function changeLocale(locale: string): Promise<void> {
    if (i18n.language === locale) {
        return;
    }

    await loadLocale(locale);
    await i18n.changeLanguage(locale);
    document.documentElement.lang = locale;
}

export default i18n;
