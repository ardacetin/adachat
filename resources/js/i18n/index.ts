import i18n from 'i18next';
import { useEffect, useState } from 'react';
import { initReactI18next } from 'react-i18next';

/** Loaded before the first render. */
export const namespaces = ['common', 'auth', 'settings'] as const;

/** Loaded on demand (e.g. only on admin pages). */
export const lazyNamespaces = ['admin'] as const;

export type Namespace =
    | (typeof namespaces)[number]
    | (typeof lazyNamespaces)[number];

/** Pass to useTranslation() when using "namespace:key" keys. */
export const allNamespaces = [...namespaces, ...lazyNamespaces] as [
    'common',
    'auth',
    'settings',
    'admin',
];
export const defaultNamespace = 'common';
export const fallbackLocale = 'en';

type Bundle = { default: Record<string, unknown> };

// Each locale/namespace file becomes its own chunk, loaded on demand.
const bundles = import.meta.glob<Bundle>('./locales/*/*.json');

// Namespaces in use; reloaded for the new language on a locale change.
const activeNamespaces = new Set<Namespace>(namespaces);

async function loadBundles(
    locale: string,
    requested: Iterable<Namespace>,
): Promise<void> {
    await Promise.all(
        [...requested].map(async (namespace) => {
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

async function loadLocale(locale: string): Promise<void> {
    await loadBundles(locale, activeNamespaces);

    if (locale !== fallbackLocale) {
        await loadBundles(fallbackLocale, activeNamespaces);
    }
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
        // Re-render when bundles are added later (lazy namespaces).
        // No suspense: lazy namespaces are loaded explicitly (useLazyNamespace).
        react: { bindI18nStore: 'added', useSuspense: false },
    });

    await loadLocale(locale);
}

export async function changeLocale(locale: string): Promise<void> {
    if (i18n.language === locale) {
        return;
    }

    await loadLocale(locale);
    await i18n.changeLanguage(locale);
    document.documentElement.lang = locale;
}

/**
 * Loads a lazy namespace; returns true once it can be rendered.
 */
export function useLazyNamespace(
    namespace: (typeof lazyNamespaces)[number],
): boolean {
    const hasBundle = () => i18n.hasResourceBundle(i18n.language, namespace);
    const [ready, setReady] = useState(hasBundle);

    useEffect(() => {
        activeNamespaces.add(namespace);

        if (hasBundle()) {
            setReady(true);

            return;
        }

        void loadLocale(i18n.language).then(() => setReady(true));
    }, [namespace]);

    return ready;
}

export default i18n;
