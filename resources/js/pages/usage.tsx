import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import UsageDetails from '@/components/budget/usage-details';
import type { UsageData } from '@/components/budget/usage-details';
import Heading from '@/components/heading';
import { usage } from '@/routes';

export default function Usage(props: UsageData) {
    const { t } = useTranslation('chat');

    return (
        <>
            <Head title={t('usage.title')} />

            <div className="mx-auto w-full max-w-4xl space-y-8 px-4 py-6">
                <Heading
                    title={t('usage.title')}
                    description={t('usage.description')}
                />

                <UsageDetails {...props} />

                <p className="text-xs text-muted-foreground">
                    {t('usage.note')}
                </p>
            </div>
        </>
    );
}

Usage.layout = {
    breadcrumbs: [{ titleKey: 'chat:usage.title', href: usage() }],
};
