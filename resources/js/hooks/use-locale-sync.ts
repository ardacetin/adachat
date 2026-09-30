import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { changeLocale } from '@/i18n';

/**
 * Keeps the i18next language in sync with the locale resolved by the
 * server (shared Inertia prop), e.g. after the user changes it in settings.
 */
export function useLocaleSync(): void {
    const { locale } = usePage().props;

    useEffect(() => {
        void changeLocale(locale.current);
    }, [locale.current]);
}
