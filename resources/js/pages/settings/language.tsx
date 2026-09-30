import { Head, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit, update } from '@/routes/language';
import type { Locale } from '@/types/global';

export default function Language() {
    const { t } = useTranslation('settings');
    const { t: tCommon } = useTranslation('common');
    const { locale } = usePage().props;

    const form = useForm<{ locale: Locale }>({ locale: locale.current });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(update.url(), { preserveScroll: true });
    };

    return (
        <>
            <Head title={t('language.title')} />

            <h1 className="sr-only">{t('language.title')}</h1>

            <form onSubmit={submit} className="space-y-6">
                <Heading
                    variant="small"
                    title={t('language.title')}
                    description={t('language.description')}
                />

                <div className="grid gap-2">
                    <Label htmlFor="locale">{t('language.label')}</Label>
                    <Select
                        value={form.data.locale}
                        onValueChange={(value) =>
                            form.setData('locale', value as Locale)
                        }
                    >
                        <SelectTrigger id="locale" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {locale.available.map((code) => (
                                <SelectItem key={code} value={code}>
                                    {tCommon(`locales.${code}`)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={form.errors.locale} />
                </div>

                <Button type="submit" disabled={form.processing}>
                    {tCommon('actions.save')}
                </Button>
            </form>
        </>
    );
}

Language.layout = {
    breadcrumbs: [
        {
            titleKey: 'settings:language.title',
            href: edit(),
        },
    ],
};
