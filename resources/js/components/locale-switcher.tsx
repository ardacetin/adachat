import { router, usePage } from '@inertiajs/react';
import { Languages } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { update } from '@/routes/locale';
import type { Locale } from '@/types/global';

/**
 * Language switch for the landing and sign-in pages. The server keeps a
 * visitor's choice in a cookie and a signed-in user's as their preference.
 */
export function LocaleSwitcher() {
    const { t } = useTranslation('common');
    const { locale } = usePage().props;

    if (locale.available.length < 2) {
        return null;
    }

    return (
        <Select
            value={locale.current}
            onValueChange={(value) =>
                router.post(
                    update.url(),
                    { locale: value as Locale },
                    { preserveScroll: true, preserveState: false },
                )
            }
        >
            <SelectTrigger
                size="sm"
                className="w-auto gap-2"
                aria-label={t('language')}
                data-test="locale-switcher"
            >
                <Languages className="size-4" aria-hidden="true" />
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {locale.available.map((code) => (
                    <SelectItem key={code} value={code}>
                        {t(`locales.${code}`)}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
